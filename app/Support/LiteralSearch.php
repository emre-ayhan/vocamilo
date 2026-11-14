<?php

namespace App\Support;

class LiteralSearch
{
    /** Escape LIKE metacharacters using an explicit, portable non-backslash escape. */
    public static function pattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($value)).'%';
    }
}
