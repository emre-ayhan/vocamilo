<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportController extends Controller
{
    public function pool(Request $r)
    {
        return $this->csv($r->user()->words()->orderBy('id')->get()->map(fn ($w) => [$w->term, $w->definition, $w->note ?? '']), 'vocabulary.csv');
    }

    public function collection(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $notes = $c->user_id === $r->user()->id ? null : DB::table('collection_notes')->where('collection_id', $c->id)->where('user_id', $r->user()->id)->pluck('note', 'word_id');

        return $this->csv($c->words()->get()->map(fn ($w) => [$w->term, $w->definition, $notes === null ? ($w->note ?? '') : ($notes[$w->id] ?? '')]), 'collection-'.$c->id.'.csv');
    }

    public static function safeCell(?string $value): string
    {
        $value = $value ?? '';

        // Defend against spreadsheet formulas even after leading whitespace/control characters.
        return (preg_match('/^[\p{Z}\p{Cf}\s\x00-\x20]*[=+@\-]/u', $value) || preg_match('/^[\p{Z}\p{Cf} ]*[\t\r\n]/u', $value)) ? "'".$value : $value;
    }

    private function csv($rows, string $filename)
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['term', 'definition', 'note'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safeCell'], $row), ',', '"', '');
            }fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
