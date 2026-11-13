<?php

namespace Tests\Unit;

use App\Services\CsvVocabulary;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CsvVocabularyTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function parse(string $text, int $term = 0, int $definition = 1, bool $header = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vocamilo-csv-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $text);

        return (new CsvVocabulary)->parse($path, $term, $definition, $header);
    }

    public function test_bom_unicode_quotes_commas_multiline_and_blank_lines(): void
    {
        $parsed = $this->parse("\xEF\xBB\xBFterm,definition\n\"ışık\",\"light, brightness\"\n\n\"quote\",\"a \"\"quoted\"\"\nmeaning\"\n");
        $this->assertSame(['term', 'definition'], $parsed['headers']);
        $this->assertSame(['term' => 'ışık', 'definition' => 'light, brightness'], $parsed['rows'][0]);
        $this->assertSame("a \"quoted\"\nmeaning", $parsed['rows'][1]['definition']);
        $this->assertCount(2, $parsed['rows']);
    }

    public function test_mapping_without_headers_and_trimming(): void
    {
        $parsed = $this->parse(" light , bright \n", 1, 0, false);
        $this->assertSame([], $parsed['headers']);
        $this->assertSame([['term' => 'bright', 'definition' => 'light']], $parsed['rows']);
    }

    public function test_header_only_file_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\n");
    }

    public function test_missing_definition_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\nword\n");
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\nword,\xFF\n");
    }

    public function test_overlong_term_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\n".str_repeat('x', 501).",definition\n");
    }

    public function test_overlong_definition_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\nword,".str_repeat('x', 5001)."\n");
    }

    public function test_more_than_two_thousand_rows_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse("term,definition\n".str_repeat("word,definition\n", 2001));
    }

    public function test_exactly_two_thousand_rows_is_supported(): void
    {
        $this->assertCount(2000, $this->parse("term,definition\n".str_repeat("word,definition\n", 2000))['rows']);
    }
}
