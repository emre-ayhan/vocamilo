<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CsvVocabulary
{
    public function parse(string $path, int $termColumn = 0, int $definitionColumn = 1, bool $header = true): array
    {
        $h = fopen($path, 'r');
        $rows = [];
        $headers = [];
        $line = 0;
        try {
            while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
                $line++;
                if ($line === 1) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '');
                    if ($header) {
                        $headers = $row;

                        continue;
                    }
                }if (count($row) === 1 && trim($row[0] ?? '') === '') {
                    continue;
                }
                $term = trim($row[$termColumn] ?? '');
                $definition = trim($row[$definitionColumn] ?? '');
                if (! mb_check_encoding($term.$definition, 'UTF-8') || $term === '' || $definition === '' || mb_strlen($term) > 500 || mb_strlen($definition) > 5000) {
                    throw ValidationException::withMessages(['file' => "Invalid term or definition on CSV row {$line}. Use UTF-8, with nonempty term and definition columns."]);
                }
                $rows[] = ['term' => $term, 'definition' => $definition];
                if (count($rows) > 2000) {
                    throw ValidationException::withMessages(['file' => 'Import up to 2,000 words at a time.']);
                }
            }
        } finally {
            fclose($h);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The CSV contains no words.']);
        }

        return ['rows' => $rows, 'headers' => $headers];
    }
}
