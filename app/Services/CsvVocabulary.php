<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CsvVocabulary
{
    public function parse(string $path, int $termColumn = 0, int $definitionColumn = 1, bool $header = true, ?int $noteColumn = 2, bool $firstRowPoolName = false): array
    {
        $h = fopen($path, 'r');
        // Remove the BOM before CSV parsing so a quoted first field is recognized.
        if (fread($h, 3) !== "\xEF\xBB\xBF") {
            rewind($h);
        }
        $rows = [];
        $headers = [];
        $line = 0;
        $poolName = null;
        try {
            while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
                $line++;
                if ($line === 1) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0] ?? '');
                    if ($firstRowPoolName) {
                        $poolName = trim($row[0] ?? '');
                        if (! mb_check_encoding($poolName, 'UTF-8') || $poolName === '' || mb_strlen($poolName) > 150 || collect(array_slice($row, 1))->contains(fn ($value) => trim($value ?? '') !== '')) {
                            throw ValidationException::withMessages(['file' => __('The first CSV row must contain only a pool name, up to 150 characters.')]);
                        }

                        continue;
                    }
                }
                if ($header && $line === ($firstRowPoolName ? 2 : 1)) {
                    $headers = $row;

                    continue;
                }
                if (count($row) === 1 && trim($row[0] ?? '') === '') {
                    continue;
                }
                $term = trim($row[$termColumn] ?? '');
                $definition = trim($row[$definitionColumn] ?? '');
                if (! mb_check_encoding($term.$definition, 'UTF-8') || $term === '' || $definition === '' || mb_strlen($term) > 500 || mb_strlen($definition) > 5000) {
                    throw ValidationException::withMessages(['file' => __('Invalid term or definition on CSV row :row. Use UTF-8, with nonempty term and definition columns.', ['row' => $line])]);
                }
                $note = $noteColumn === null ? '' : ($row[$noteColumn] ?? '');
                if (! mb_check_encoding($note, 'UTF-8')) {
                    throw ValidationException::withMessages(['file' => __('Invalid personal note on CSV row :row. Use UTF-8 and at most 5,000 characters.', ['row' => $line])]);
                }
                $note = preg_replace('/^\s+|\s+$/u', '', $note);
                if (mb_strlen($note) > 5000) {
                    throw ValidationException::withMessages(['file' => __('Invalid personal note on CSV row :row. Use UTF-8 and at most 5,000 characters.', ['row' => $line])]);
                }
                $rows[] = ['term' => $term, 'definition' => $definition] + ($note !== '' ? ['note' => $note] : []);
                if (count($rows) > 2000) {
                    throw ValidationException::withMessages(['file' => __('Import up to 2,000 words at a time.')]);
                }
            }
        } finally {
            fclose($h);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => __('The CSV contains no words.')]);
        }

        return ['rows' => $rows, 'headers' => $headers, 'pool_name' => $poolName];
    }
}
