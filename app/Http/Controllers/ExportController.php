<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function pool(Request $r)
    {
        $d = $r->validate(['pool_id' => 'required|integer']);
        $pool = $r->user()->wordPools()->findOrFail($d['pool_id']);

        return $this->csv($pool->words()->where('words.user_id', $r->user()->id)->reorder()->orderBy('words.id')->get()->map(fn ($w) => [$w->term, $w->definition, $w->note ?? '']), 'vocabulary-'.$pool->id.'.csv');
    }

    public function collection(Request $r, int $collection)
    {
        $c = $r->user()->collections()->findOrFail($collection);

        return $this->csv($c->words()->get()->map(fn ($w) => [$w->term, $w->definition, $w->note ?? '']), 'collection-'.$c->id.'.csv');
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
