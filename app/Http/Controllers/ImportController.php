<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CsvVocabulary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Import/Index', ['preview' => null, 'collections' => $r->user()->collections()->get(['id', 'title'])]);
    }

    public function preview(Request $r, CsvVocabulary $csv)
    {
        $r->session()->forget('csv_preview');
        $d = $r->validate(['file' => 'required|file|max:2048', 'term_column' => 'nullable|integer|min:0|max:49', 'definition_column' => 'nullable|integer|min:0|max:49', 'has_header' => 'nullable|boolean']);
        $parsed = $csv->parse($r->file('file')->getRealPath(), (int) ($d['term_column'] ?? 0), (int) ($d['definition_column'] ?? 1), $r->boolean('has_header', true));
        $pool = $r->user()->words()->get()->groupBy(fn ($word) => $this->termKey($word->term));
        $groups = collect($parsed['rows'])->groupBy(fn ($row) => $this->termKey($row['term']));
        $rows = [];
        $warnings = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates = 0;
        foreach ($groups as $key => $group) {
            $row = $group->first();
            if ($group->pluck('definition')->uniqueStrict()->count() > 1) {
                $skipped += $group->count();
                $warnings[] = ['term' => $row['term'], 'message' => __('Conflicting definitions for this term in the CSV. All its rows were skipped.')];

                continue;
            }
            $matches = $pool->get($key, collect());
            if ($matches->count() > 1) {
                $skipped += $group->count();
                $warnings[] = ['term' => $row['term'], 'message' => __('More than one word in your pool matches this term. Its CSV rows were skipped.')];

                continue;
            }
            $duplicates += $group->count() - 1;
            $skipped += $group->count() - 1;
            $word = $matches->first();
            $action = ! $word ? 'create' : ($word->definition === $row['definition'] ? 'unchanged' : 'update');
            if ($action === 'create') {
                $created++;
            } elseif ($action === 'update') {
                $updated++;
            } else {
                $skipped++;
                $duplicates++;
            }
            $rows[] = $row + ['key' => $key, 'action' => $action, 'snapshot' => $word ? $word->only('id', 'term', 'definition') : null];
        }
        $r->session()->put('csv_preview', ['version' => 2, 'user_id' => $r->user()->id, 'rows' => $rows, 'expires_at' => now()->addMinutes(30)->timestamp]);
        $display = array_map(fn ($row) => collect($row)->only('term', 'definition', 'action')->all(), array_slice($rows, 0, 50));

        return Inertia::render('Import/Index', ['preview' => ['rows' => $display, 'total' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'duplicates' => $duplicates, 'warnings' => $warnings, 'headers' => $parsed['headers']], 'collections' => $r->user()->collections()->get(['id', 'title'])]);
    }

    private function termKey(string $term): string
    {
        return mb_strtolower(trim($term));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['collection_id' => 'nullable|integer']);
        $preview = $r->session()->get('csv_preview');
        abort_unless($preview && ($preview['version'] ?? null) === 2 && $preview['user_id'] === $r->user()->id && $preview['expires_at'] >= time(), 422, __('Preview expired. Upload the CSV again.'));
        $c = isset($d['collection_id']) ? $r->user()->collections()->findOrFail($d['collection_id']) : null;
        DB::transaction(function () use ($r, $preview, $c) {
            // Serialize imports for the learner and lock existing rows before validating snapshots.
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $pool = $r->user()->words()->lockForUpdate()->get()->groupBy(fn ($word) => $this->termKey($word->term));
            foreach ($preview['rows'] as $row) {
                $matches = $pool->get($row['key'], collect());
                $snapshot = $row['snapshot'];
                $same = $snapshot === null ? $matches->isEmpty() : ($matches->count() === 1 && $matches->first()->only('id', 'term', 'definition') === $snapshot);
                if (! $same) {
                    throw ValidationException::withMessages(['file' => __('Your word pool changed after preview. Upload the CSV again before importing.')]);
                }
            }
            foreach ($preview['rows'] as $row) {
                if ($row['snapshot'] === null) {
                    $word = $r->user()->words()->create(['term' => $row['term'], 'definition' => $row['definition']]);
                } else {
                    $word = $pool->get($row['key'])->first();
                    if ($row['action'] === 'update') {
                        $word->update(['definition' => $row['definition']]);
                    }
                }
                if ($c && ! $c->words()->where('words.id', $word->id)->exists()) {
                    $c->words()->attach($word->id, ['added_at' => now()]);
                }
            }
        });
        $r->session()->forget('csv_preview');

        return redirect($c ? '/collections/'.$c->id : '/words')->with('success', __('CSV import completed.'));
    }
}
