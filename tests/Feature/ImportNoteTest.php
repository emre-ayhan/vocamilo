<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImportNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $pool = $owner->wordPools()->create(['name' => 'English', 'language' => 'en']);
        $word = $owner->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'Original owner note']);
        $pool->words()->attach($word);
        $this->actingAs($owner);

        return [$owner, $pool, $word];
    }

    private function preview($pool, string $rows)
    {
        return $this->post('/imports/preview', ['pool_id' => $pool->id, 'has_header' => true, 'file' => UploadedFile::fake()->createWithContent('notes.csv', "term,definition,note\n".$rows."\n")]);
    }

    private function commit(array $extra = [])
    {
        return $this->postJson('/imports/store', $extra + ['preview_id' => session('csv_preview.preview_id')]);
    }

    public function test_note_only_update_and_new_word_note_are_imported_without_replacing_identity(): void
    {
        [, $pool, $word] = $this->fixture();
        $this->preview($pool, "word,meaning,New owner note\nfresh,new definition,Fresh note")
            ->assertInertia(fn (Assert $p) => $p->where('preview.updated', 1)->where('preview.created', 1));
        $this->assertSame(4, session('csv_preview.version'));
        $this->commit()->assertRedirect();
        $this->assertSame('New owner note', $word->fresh()->note);
        $this->assertSame('meaning', $word->fresh()->definition);
        $this->assertSame($word->id, $pool->words()->where('term', 'word')->first()->id);
        $this->assertSame('Fresh note', $pool->words()->where('term', 'fresh')->first()->note);
    }

    public function test_missing_empty_and_whitespace_notes_preserve_existing_notes(): void
    {
        [, $pool, $word] = $this->fixture();
        foreach (['word,changed', 'word,changed,', 'word,changed,   '] as $row) {
            $this->preview($pool, $row)->assertOk();
            $this->commit()->assertRedirect();
            $this->assertSame('Original owner note', $word->fresh()->note);
            $this->assertSame('changed', $word->fresh()->definition);
        }
    }

    public function test_duplicate_conflicting_nonblank_notes_skip_and_blank_plus_unique_note_merge(): void
    {
        [, $pool, $word] = $this->fixture();
        $this->preview($pool, "word,changed,First\nWORD,changed,Second\nfresh,new,Safe")
            ->assertInertia(fn (Assert $p) => $p->where('preview.skipped', 2)->where('preview.created', 1));
        $this->commit()->assertRedirect();
        $this->assertSame('Original owner note', $word->fresh()->note);
        $this->assertSame('meaning', $word->fresh()->definition);
        foreach (["word,meaning,\nWORD,meaning,Merged", "word,meaning,Merged again\nWORD,meaning,"] as $rows) {
            $this->preview($pool, $rows)->assertInertia(fn (Assert $p) => $p->where('preview.updated', 1));
            $this->commit()->assertRedirect();
        }
        $this->assertSame('Merged again', $word->fresh()->note);
    }

    public function test_owner_note_changed_after_preview_rejects_entire_commit_and_old_preview_version(): void
    {
        [$owner, $pool, $word] = $this->fixture();
        $c = $owner->collections()->create(['title' => 'Owner collection']);
        $c->words()->attach($word);
        $this->preview($pool, "word,changed,CSV note\nfresh,meaning,Fresh")->assertOk();
        $this->putJson('/collections/'.$c->id.'/words/'.$word->id.'/note', ['note' => 'Edited after preview'])->assertOk();
        $this->commit()->assertUnprocessable();
        $this->assertSame('Edited after preview', $word->fresh()->note);
        $this->assertSame('meaning', $word->fresh()->definition);
        $this->assertDatabaseMissing('words', ['term' => 'fresh']);
        $this->preview($pool, 'word,changed,CSV note')->assertOk();
        $preview = session('csv_preview');
        $preview['version'] = 3;
        $this->withSession(['csv_preview' => $preview]);
        $this->commit()->assertUnprocessable();
        $this->assertSame('Edited after preview', $word->fresh()->note);
    }

    public function test_shared_word_owner_note_updates_all_owner_views_but_not_member_or_public(): void
    {
        [$owner, $pool, $word] = $this->fixture();
        $second = $owner->wordPools()->create(['name' => 'Reused', 'language' => 'en']);
        $second->words()->attach($word);
        $member = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Shared']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $c->words()->attach($word);
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $word->id, 'note' => 'Member private']);
        $this->preview($pool, 'word,meaning,Imported owner private')->assertOk();
        $this->commit()->assertRedirect();
        $this->assertSame('Imported owner private', $second->words()->first()->note);
        $this->getJson('/collections/'.$c->id.'/words')->assertJsonPath('data.0.note', 'Imported owner private');
        $this->actingAs($member)->getJson('/collections/'.$c->id.'/words')->assertJsonPath('data.0.note', 'Member private')->assertDontSee('Imported owner private');
        $this->preview($pool, 'word,hacked,hacked')->assertNotFound();
        Auth::logout();
        $public = $this->getJson('/s/'.$c->share_uuid.'/words')->assertOk()->assertDontSee('Imported owner private')->assertDontSee('Member private');
        $this->assertArrayNotHasKey('note', $public->json('data.0'));
        $this->assertSame('Imported owner private', $word->fresh()->note);
    }

    public function test_invalid_notes_reject_preview_atomically_and_multiline_unicode_note_roundtrips(): void
    {
        [, $pool, $word] = $this->fixture();
        foreach ([str_repeat('x', 5001), "\xFF"] as $note) {
            $this->preview($pool, 'word,meaning,'.$note)->assertSessionHasErrors('file');
            $this->assertNull(session('csv_preview'));
        }
        $note = "Türkçe, português\nSecond line";
        $this->preview($pool, 'word,meaning,"'.$note.'"')->assertOk();
        $this->commit()->assertRedirect();
        $this->assertSame($note, $word->fresh()->note);
    }

    public function test_same_term_in_other_pool_and_other_user_keeps_private_note(): void
    {
        [$owner, $pool, $word] = $this->fixture();
        $otherPool = $owner->wordPools()->create(['name' => 'Different', 'language' => 'pt']);
        $otherWord = $owner->words()->create(['term' => 'word', 'definition' => 'different', 'note' => 'Other pool note']);
        $otherPool->words()->attach($otherWord);
        $foreign = User::factory()->create()->words()->create(['term' => 'word', 'definition' => 'foreign', 'note' => 'Foreign note']);
        $this->preview($pool, 'word,meaning,Selected note')->assertOk();
        $this->commit()->assertRedirect();
        $this->assertSame('Selected note', $word->fresh()->note);
        $this->assertSame('Other pool note', $otherWord->fresh()->note);
        $this->assertSame('Foreign note', $foreign->fresh()->note);
    }

    public function test_note_column_can_be_explicitly_disabled_or_mapped(): void
    {
        [, $pool, $word] = $this->fixture();
        foreach ([null, 3] as $column) {
            $this->post('/imports/preview', ['pool_id' => $pool->id, 'has_header' => true, 'note_column' => $column, 'file' => UploadedFile::fake()->createWithContent('notes.csv', "term,definition,ignored,note\nword,meaning,Ignored,Mapped\n")])->assertOk();
            $this->commit()->assertRedirect();
            $this->assertSame($column === null ? 'Original owner note' : 'Mapped', $word->fresh()->note);
        }
    }

    public function test_member_note_edit_does_not_invalidate_owner_preview_or_get_overwritten(): void
    {
        [$owner, $pool, $word] = $this->fixture();
        $member = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Shared']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $c->words()->attach($word);
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->preview($pool, 'word,meaning,Imported owner note')->assertOk();
        $this->actingAs($member)->putJson('/collections/'.$c->id.'/words/'.$word->id.'/note', ['note' => 'New member note'])->assertOk();
        $this->actingAs($owner);
        $this->commit()->assertRedirect();
        $this->assertSame('Imported owner note', $word->fresh()->note);
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $member->id, 'word_id' => $word->id, 'note' => 'New member note']);
    }
}
