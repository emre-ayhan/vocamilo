<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CsvVocabulary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['pool_id' => 'nullable|integer']);
        $pool = isset($d['pool_id']) ? $r->user()->wordPools()->findOrFail($d['pool_id']) : null;

        return Inertia::render('Import/Index', ['preview' => null, 'selectedPoolId' => $pool?->id, 'pools' => $r->user()->wordPools()->get(['id', 'name', 'language', 'text_direction']), 'collections' => $r->user()->collections()->get(['id', 'title'])]);
    }

    public function preview(Request $r, CsvVocabulary $csv)
    {
        $r->session()->forget('csv_preview');
        $d = $r->validate(['pool_id' => 'required|integer', 'file' => 'required|file|max:2048', 'term_column' => 'nullable|integer|min:0|max:49', 'definition_column' => 'nullable|integer|min:0|max:49', 'has_header' => 'nullable|boolean']);
        $parsed = $csv->parse($r->file('file')->getRealPath(), (int) ($d['term_column'] ?? 0), (int) ($d['definition_column'] ?? 1), $r->boolean('has_header', true));
        [$target, $snapshot] = DB::transaction(function () use ($r, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $target = $r->user()->wordPools()->findOrFail($d['pool_id']);

            return [$target, $this->snapshot($target, $r->user()->id)];
        });
        $pool = collect($snapshot)->groupBy(fn ($word) => $this->termKey($word['term']));
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
            $action = ! $word ? 'create' : ($word['definition'] === $row['definition'] ? 'unchanged' : 'update');
            if ($action === 'create') {
                $created++;
            } elseif ($action === 'update') {
                $updated++;
            } else {
                $skipped++;
                $duplicates++;
            }
            $rows[] = $row + ['key' => $key, 'action' => $action, 'snapshot' => $word ?: null];
        }
        $previewId = (string) Str::uuid();
        $r->session()->put('csv_preview', ['preview_id' => $previewId, 'version' => 3, 'pool_id' => $target->id, 'pool_snapshot' => $target->only('id', 'name', 'language', 'text_direction'), 'membership_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'user_id' => $r->user()->id, 'rows' => $rows, 'expires_at' => now()->addMinutes(30)->timestamp]);
        $display = array_map(fn ($row) => collect($row)->only('term', 'definition', 'action')->all(), array_slice($rows, 0, 50));

        return Inertia::render('Import/Index', ['selectedPoolId' => $target->id, 'pools' => $r->user()->wordPools()->get(['id', 'name', 'language', 'text_direction']), 'preview' => ['preview_id' => $previewId, 'pool_id' => $target->id, 'rows' => $display, 'total' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'duplicates' => $duplicates, 'warnings' => $warnings, 'headers' => $parsed['headers']], 'collections' => $r->user()->collections()->get(['id', 'title'])]);
    }

    private function termKey(string $term): string
    {
        return mb_strtolower(trim($term));
    }

    private function snapshot($pool, int $userId): array
    {
        return $pool->words()->where('words.user_id', $userId)->reorder()->orderBy('words.id')->get(['words.id', 'words.term', 'words.definition', 'words.text_direction'])->map(fn ($word) => $word->only('id', 'term', 'definition', 'text_direction'))->all();
    }

    public function store(Request $r)
    {
        $d = $r->validate(['pool_id' => 'nullable|integer', 'collection_id' => 'nullable|integer']);
        $preview = $r->session()->get('csv_preview');
        abort_unless($preview && ($preview['version'] ?? null) === 3 && $preview['user_id'] === $r->user()->id && $preview['expires_at'] >= time(), 422, __('Preview expired. Upload the CSV again.'));
        $r->validate(['preview_id' => 'required|uuid']);
        if (! is_string($preview['preview_id'] ?? null) || ! hash_equals($preview['preview_id'], $r->input('preview_id'))) {
            throw ValidationException::withMessages(['file' => __('This preview was replaced. Upload the CSV again.')]);
        }
        if (isset($d['pool_id']) && (int) $d['pool_id'] !== $preview['pool_id']) {
            throw ValidationException::withMessages(['file' => __('The selected pool changed. Upload the CSV again.')]);
        }
        $collectionId = DB::transaction(function () use ($r, $preview, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $target = $r->user()->wordPools()->whereKey($preview['pool_id'])->lockForUpdate()->firstOrFail();
            $c = isset($d['collection_id']) ? $r->user()->collections()->whereKey($d['collection_id'])->lockForUpdate()->firstOrFail() : null;
            if ($target->only('id', 'name', 'language', 'text_direction') !== $preview['pool_snapshot'] || ! hash_equals($preview['membership_hash'] ?? '', hash('sha256', json_encode($this->snapshot($target, $r->user()->id), JSON_THROW_ON_ERROR)))) {
                throw ValidationException::withMessages(['file' => __('Your word pool changed after preview. Upload the CSV again before importing.')]);
            }
            $pool = $target->words()->where('words.user_id', $r->user()->id)->get()->keyBy('id');
            if ($pool->contains(fn ($word) => $word->text_direction !== $target->text_direction)) {
                throw ValidationException::withMessages(['file' => __('Word and pool text directions must match before importing.')]);
            }
            foreach ($preview['rows'] as $row) {
                if ($row['snapshot'] === null) {
                    $word = $r->user()->words()->create(['term' => $row['term'], 'definition' => $row['definition'], 'text_direction' => $target->text_direction]);
                    $target->words()->attach($word->id);
                } else {
                    $word = $pool->get($row['snapshot']['id']);
                    if ($row['action'] === 'update') {
                        $word->update(['definition' => $row['definition']]);
                    }
                }
                if ($c && ! $c->words()->where('words.id', $word->id)->exists()) {
                    $c->words()->attach($word->id, ['added_at' => now()]);
                }
            }

            return $c?->id;
        });
        $r->session()->forget('csv_preview');

        return redirect($collectionId ? '/collections/'.$collectionId : '/word-pools/'.$preview['pool_id'])->with('success', __('CSV import completed.'));
    }
}
