<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CsvUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function preview(string $text)
    {
        return $this->post('/imports/preview', ['file' => UploadedFile::fake()->createWithContent('words.csv', "term,definition\n".$text), 'has_header' => true]);
    }

    public function test_matching_term_updates_definition_preserving_spelling_note_id_and_links(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $w = $u->words()->create(['term' => '  Bright  ', 'definition' => 'old', 'note' => 'secret']);
        $c = $u->collections()->create(['title' => 'Old']);
        $c->words()->attach($w);
        $target = $u->collections()->create(['title' => 'New']);
        $foreign = User::factory()->create()->words()->create(['term' => 'bright', 'definition' => 'foreign']);
        $this->preview("BRIGHT,new definition\n")->assertInertia(fn (Assert $p) => $p->where('preview.updated', 1)->where('preview.created', 0));
        $this->assertSame('old', $w->fresh()->definition);
        $this->post('/imports/store', ['collection_id' => $target->id])->assertRedirect();
        $this->assertSame('new definition', $w->fresh()->definition);
        $this->assertSame('  Bright  ', $w->fresh()->term);
        $this->assertSame('secret', $w->fresh()->note);
        $this->assertSame('foreign', $foreign->fresh()->definition);
        $this->assertSame(1, $c->words()->count());
        $this->assertSame($w->id, $target->words()->first()->id);
    }

    public function test_ambiguous_existing_and_conflicting_csv_terms_skip_with_warnings_identical_collapses(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        foreach (['Word', 'word'] as $term) {
            $u->words()->create(['term' => $term, 'definition' => 'old']);
        }
        $this->preview("word,new\nconflict,one\nCONFLICT,two\nfresh,new\nFRESH,new\n")->assertInertia(fn (Assert $p) => $p->where('preview.created', 1)->where('preview.updated', 0)->where('preview.skipped', 4)->has('preview.warnings', 2));
        $this->post('/imports/store')->assertRedirect();
        $this->assertDatabaseCount('words', 3);
        $this->assertDatabaseMissing('words', ['term' => 'conflict']);
        $this->assertSame(2, $u->words()->where('definition', 'old')->count());
    }

    public function test_pool_changes_after_preview_abort_entire_batch_and_leave_preview_for_reupload(): void
    {
        foreach (['definition', 'term', 'duplicate', 'delete', 'new_collision'] as $change) {
            $u = User::factory()->create();
            $this->actingAs($u);
            $w = $u->words()->create(['term' => 'existing', 'definition' => 'old']);
            $this->preview("new,new value\nexisting,replacement\n")->assertOk();
            if ($change === 'definition') {
                $w->update(['definition' => 'manual']);
            }
            if ($change === 'term') {
                $w->update(['term' => 'renamed']);
            }
            if ($change === 'duplicate') {
                $u->words()->create(['term' => 'EXISTING', 'definition' => 'duplicate']);
            }
            if ($change === 'delete') {
                $w->delete();
            }
            if ($change === 'new_collision') {
                $u->words()->create(['term' => 'NEW', 'definition' => 'manual']);
            }
            $count = $u->words()->count();
            $this->post('/imports/store')->assertSessionHasErrors('file')->assertSessionHas('csv_preview');
            $this->assertSame($count, $u->words()->count());
            $this->assertDatabaseMissing('words', ['user_id' => $u->id, 'definition' => 'replacement']);
        }
    }

    public function test_note_only_changes_are_preserved_and_unchanged_word_can_join_target_collection(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $w = $u->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'old']);
        $c = $u->collections()->create(['title' => 'Target']);
        $this->preview("word,meaning\n")->assertInertia(fn (Assert $p) => $p->where('preview.skipped', 1)->where('preview.total', 1));
        $w->update(['note' => 'fresh note']);
        $this->post('/imports/store', ['collection_id' => $c->id])->assertRedirect();
        $this->assertSame('fresh note', $w->fresh()->note);
        $this->assertSame($w->id, $c->words()->first()->id);
    }

    public function test_reupload_is_idempotent_and_legacy_preview_is_rejected_cleanly(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->withSession(['csv_preview' => ['user_id' => $u->id, 'expires_at' => time() + 60, 'rows' => [['term' => 'legacy', 'definition' => 'old']]]])->post('/imports/store')->assertUnprocessable();
        $this->assertDatabaseCount('words', 0);
        $this->preview("word,meaning\n")->assertOk();
        $this->post('/imports/store')->assertRedirect();
        $this->preview("WORD,meaning\n")->assertInertia(fn (Assert $p) => $p->where('preview.created', 0)->where('preview.updated', 0)->where('preview.skipped', 1));
        $this->post('/imports/store')->assertRedirect();
        $this->assertDatabaseCount('words', 1);
        $this->post('/imports/store')->assertUnprocessable();
    }
}
