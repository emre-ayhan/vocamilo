<?php

namespace App\Support;

use App\Http\Controllers\StudySessionController;
use Illuminate\Validation\ValidationException;

class LetterPlacement
{
    public static function parts(string $text): array
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $parts = [];
        $offset = 0;
        while ($offset < strlen($normalized)) {
            $part = grapheme_extract($normalized, 1, GRAPHEME_EXTR_COUNT, $offset, $next);
            if ($part === false || $next <= $offset) {
                throw ValidationException::withMessages(['answers' => __('Complete every editable letter and include its first entry.')]);
            }
            $parts[] = $part;
            $offset = $next;
        }

        return $parts;
    }

    public static function layout(string $expected): array
    {
        $parts = self::parts($expected);
        $letters = array_keys(array_filter($parts, fn ($part) => preg_match('/[\\p{L}\\p{N}]/u', $part)));
        $count = min(count($letters) >= 3 ? (int) ceil(count($letters) * .4) : 0, max(0, count($letters) - 1));
        $hints = [];
        for ($i = 0; $i < $count; $i++) {
            $hints[] = $letters[(int) floor(($i + 1) * count($letters) / ($count + 1))];
        }
        $map = fn ($indices) => array_values(array_map(fn ($index) => ['index' => $index, 'expected' => $parts[$index]], $indices));

        return ['supported' => count($parts) <= 240 && count($letters) > 0, 'parts' => $parts, 'written' => $map(array_diff($letters, $hints)), 'rearrange' => $map($letters)];
    }

    public static function score(array $layout, string $type, array $entries, string $answer): array
    {
        $fail = fn () => throw ValidationException::withMessages(['answers' => __('Complete every editable letter and include its first entry.')]);
        $slots = $layout[$type];
        if (count($entries) !== count($slots)) {
            $fail();
        }
        $indexed = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['index', 'value']) || ! isset($entry['index'], $entry['value']) || ! is_int($entry['index']) || ! is_string($entry['value']) || mb_strlen($entry['value']) > 5000 || isset($indexed[$entry['index']]) || count(self::parts($entry['value'])) !== 1 || ! preg_match('/[\\p{L}\\p{N}]/u', $entry['value'])) {
                $fail();
            }
            $indexed[$entry['index']] = $entry['value'];
        }
        if (StudySessionController::normalize($answer) !== StudySessionController::normalize(implode('', $layout['parts']))) {
            $fail();
        }
        $final = self::parts($answer);
        if (count($final) !== count($layout['parts'])) {
            $fail();
        }
        $editable = array_column($slots, 'index');
        foreach ($layout['parts'] as $index => $part) {
            if (in_array($index, $editable, true)) {
                if (! preg_match('/[\\p{L}\\p{N}]/u', $final[$index])) {
                    $fail();
                }
            } elseif (StudySessionController::normalize($final[$index]) !== StudySessionController::normalize($part)) {
                $fail();
            }
        }
        $correct = 0;
        foreach ($slots as $slot) {
            if (! isset($indexed[$slot['index']])) {
                $fail();
            }
            $correct += (int) (StudySessionController::normalize($indexed[$slot['index']]) === StudySessionController::normalize($slot['expected']));
        }

        return ['first_entries' => $entries, 'correct_slots' => $correct, 'total_slots' => count($slots), 'passed' => count($slots) > 0 && $correct * 5 >= count($slots) * 4];
    }
}
