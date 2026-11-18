<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CsvPoolNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
    }

    private function preview(string $csv, array $options = [])
    {
        return $this->post('/imports/preview', $options + [
            'first_row_pool_name' => true,
            'new_pool_language' => 'tr',
            'new_pool_direction' => 'ltr',
            'has_header' => true,
            'file' => UploadedFile::fake()->createWithContent('words.csv', $csv),
        ]);
    }

    public function test_preview_reads_quoted_bom_name_without_creating_pool_and_commit_imports_into_it(): void
    {
        $this->preview("\xEF\xBB\xBF\"Travel, words\"\r\nterm,definition,note\r\nmerhaba,hello,greeting\r\ngüle güle,goodbye,\r\n")
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.new_pool.name', 'Travel, words')
                ->where('preview.new_pool.language', 'tr')
                ->where('preview.pool_id', null)
                ->where('preview.total', 2)
                ->where('preview.created', 2)
                ->where('preview.headers', ['term', 'definition', 'note']));
        $user = auth()->user();
        $this->assertSame(0, $user->wordPools()->count());
        $this->assertSame(0, $user->words()->count());
        $id = session('csv_preview.preview_id');
        $this->post('/imports/store', ['preview_id' => $id, 'pool_id' => null])
            ->assertRedirect('/word-pools/'.$user->wordPools()->firstOrFail()->id);
        $pool = $user->wordPools()->firstOrFail();
        $this->assertSame('Travel, words', $pool->name);
        $this->assertSame('tr', $pool->language);
        $this->assertSame(2, $pool->words()->count());
        $this->assertSame('greeting', $pool->words()->where('term', 'merhaba')->firstOrFail()->note);
        $this->postJson('/imports/store', ['preview_id' => $id])->assertUnprocessable();
        $this->assertSame(1, $user->wordPools()->count());
    }

    public function test_no_column_headers_keeps_first_word_and_new_words_inherit_rtl(): void
    {
        $this->preview("Arabic words\nمرحبا,welcome\nسلام,peace\n", [
            'has_header' => false, 'new_pool_language' => 'ar', 'new_pool_direction' => 'rtl',
        ])->assertInertia(fn (Assert $page) => $page->where('preview.total', 2)->where('preview.headers', []));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $pool = auth()->user()->wordPools()->firstOrFail();
        $this->assertSame('rtl', $pool->text_direction);
        $this->assertSame(['rtl', 'rtl'], $pool->words()->pluck('text_direction')->all());
    }

    public function test_invalid_name_or_missing_words_does_not_create_a_pool(): void
    {
        foreach (["\nword,definition", "word,definition\nnext,meaning", str_repeat('x', 151)."\nword,definition", "New pool\nterm,definition\n", "\xFF\nterm,definition\nword,meaning"] as $csv) {
            $this->preview($csv)->assertSessionHasErrors('file');
            $this->assertNull(session('csv_preview'));
            $this->assertSame(0, auth()->user()->wordPools()->count());
        }
    }

    public function test_new_pool_rolls_back_when_selected_collection_is_not_owned(): void
    {
        $foreign = User::factory()->create()->collections()->create(['title' => 'Private']);
        $this->preview("My pool\nterm,definition\nword,meaning")->assertOk();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id'), 'collection_id' => $foreign->id])->assertNotFound();
        $this->assertSame(0, auth()->user()->wordPools()->count());
        $this->assertSame(0, auth()->user()->words()->count());
    }

    public function test_new_pool_commit_cannot_be_redirected_into_an_existing_pool_or_renamed_by_payload(): void
    {
        $existing = auth()->user()->wordPools()->create(['name' => 'Existing', 'language' => 'en']);
        $this->preview("New pool\nterm,definition\nword,meaning")->assertOk();
        $id = session('csv_preview.preview_id');
        $this->post('/imports/store', ['preview_id' => $id, 'pool_id' => $existing->id])->assertSessionHasErrors('file');
        $this->post('/imports/store', ['preview_id' => $id, 'new_pool' => ['name' => 'Tampered']])->assertRedirect();
        $this->assertSame(0, $existing->words()->count());
        $this->assertDatabaseHas('word_pools', ['user_id' => auth()->id(), 'name' => 'New pool']);
        $this->assertDatabaseMissing('word_pools', ['name' => 'Tampered']);
    }

    public function test_new_pool_language_and_direction_are_required_and_validated(): void
    {
        foreach ([['new_pool_language' => null], ['new_pool_language' => 'invalid language'], ['new_pool_direction' => null], ['new_pool_direction' => 'invalid']] as $options) {
            $this->preview("New pool\nterm,definition\nword,meaning", $options)->assertSessionHasErrors(array_key_first($options));
            $this->assertSame(0, auth()->user()->wordPools()->count());
        }
    }
}
