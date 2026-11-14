<?php

namespace App\Http\Controllers;

use App\Services\CsvVocabulary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $unique = [];
        $duplicates = 0;
        foreach ($parsed['rows'] as $row) {
            $key = mb_strtolower($row['term'])."\0".$row['definition'];
            if (isset($unique[$key]) || $r->user()->words()->where('term', $row['term'])->where('definition', $row['definition'])->exists()) {
                $duplicates++;

                continue;
            }$unique[$key] = $row;
        }$rows = array_values($unique);
        $r->session()->put('csv_preview', ['user_id' => $r->user()->id, 'rows' => $rows, 'expires_at' => now()->addMinutes(30)->timestamp]);

        return Inertia::render('Import/Index', ['preview' => ['rows' => array_slice($rows, 0, 50), 'total' => count($rows), 'duplicates' => $duplicates, 'headers' => $parsed['headers']], 'collections' => $r->user()->collections()->get(['id', 'title'])]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['collection_id' => 'nullable|integer']);
        $preview = $r->session()->get('csv_preview');
        abort_unless($preview && $preview['user_id'] === $r->user()->id && $preview['expires_at'] >= time(), 422, __('Preview expired. Upload the CSV again.'));
        $c = isset($d['collection_id']) ? $r->user()->collections()->findOrFail($d['collection_id']) : null;
        DB::transaction(function () use ($r, $preview, $c) {
            foreach ($preview['rows'] as $row) {
                $word = $r->user()->words()->firstOrCreate($row);
                if ($c) {
                    if (! $c->words()->where('words.id', $word->id)->exists()) {
                        $c->words()->attach($word->id, ['added_at' => now()]);
                    }
                }
            }
        });
        $r->session()->forget('csv_preview');

        return redirect($c ? '/collections/'.$c->id : '/words')->with('success', __('CSV import completed.'));
    }
}
