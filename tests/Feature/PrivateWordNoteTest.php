<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivateWordNoteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $word = $owner->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'owner secret']);
        $collections = [];
        foreach (['One', 'Two'] as $title) {
            $c = $owner->collections()->create(['title' => $title]);
            $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
            $c->words()->attach($word);
            $c->members()->attach([$member->id, $other->id], ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
            $collections[] = $c;
        }

        return [$owner, $member, $other, $word, ...$collections];
    }

    private function url($c, $w): string
    {
        return '/collections/'.$c->id.'/words/'.$w->id.'/note';
    }

    public function test_member_note_is_global_to_word_and_private_in_reads_study_export_and_clear(): void
    {
        [$owner,$member,$other,$w,$a,$b] = $this->fixture();
        $this->actingAs($member);
        $this->getJson($this->url($a, $w))->assertOk()->assertExactJson(['note' => null]);
        $this->putJson($this->url($a, $w), ['note' => 'my private note'])->assertOk()->assertExactJson(['note' => 'my private note']);
        $this->getJson($this->url($b, $w))->assertExactJson(['note' => 'my private note']);
        $this->getJson('/collections/'.$b->id.'/words')->assertJsonPath('data.0.note', 'my private note');
        $s = $this->postJson('/collections/'.$b->id.'/sessions', ['mode' => 'flashcards', 'settings' => ['type' => 'choice', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
        $this->assertSame('my private note', $s['questions'][0]['note']);
        $this->get('/collections/'.$b->id.'/export')->assertNotFound();
        $csv = $this->actingAs($owner)->get('/collections/'.$b->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('owner secret', $csv);
        $this->assertStringNotContainsString('my private note', $csv);
        $this->actingAs($other)->getJson($this->url($b, $w))->assertExactJson(['note' => null]);
        $this->actingAs($owner)->getJson($this->url($b, $w))->assertExactJson(['note' => 'owner secret']);
        $this->actingAs($member)->putJson($this->url($b, $w), ['note' => null])->assertOk();
        $this->getJson($this->url($a, $w))->assertExactJson(['note' => null]);
        $this->assertSame('owner secret', $w->fresh()->note);
    }

    public function test_owner_edits_remain_consistent_with_pool_and_notes_validate_and_authorize(): void
    {
        [$owner,$member,$other,$w,$a,$b] = $this->fixture();
        $this->actingAs($owner);
        $this->putJson($this->url($a, $w), ['note' => 'new owner note'])->assertOk();
        $this->assertSame('new owner note', $w->fresh()->note);
        $this->putJson($this->url($a, $w), [])->assertUnprocessable();
        $this->putJson($this->url($a, $w), ['note' => str_repeat('a', 5001)])->assertUnprocessable();
        $foreign = $other->words()->create(['term' => 'other', 'definition' => 'other']);
        $this->getJson($this->url($a, $foreign))->assertNotFound();
        $a->members()->detach($member);
        $this->actingAs($member)->getJson($this->url($a, $w))->assertNotFound();
        $this->putJson($this->url($a, $w), ['note' => 'denied'])->assertNotFound();
        $this->post('/logout');
        $this->getJson($this->url($b, $w))->assertUnauthorized();
        $this->putJson($this->url($b, $w), ['note' => 'denied'])->assertUnauthorized();
    }

    public function test_migration_chooses_latest_then_id_and_preserves_all_legacy_conflicts_including_clear(): void
    {
        [$owner,$member,$other,$w,$a,$b] = $this->fixture();
        $rows = [['collection_id' => $a->id, 'user_id' => $member->id, 'word_id' => $w->id, 'note' => 'older', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()], ['collection_id' => $b->id, 'user_id' => $member->id, 'word_id' => $w->id, 'note' => 'newer', 'created_at' => now(), 'updated_at' => now()], ['collection_id' => $a->id, 'user_id' => $other->id, 'word_id' => $w->id, 'note' => 'tie first', 'created_at' => now(), 'updated_at' => now()], ['collection_id' => $b->id, 'user_id' => $other->id, 'word_id' => $w->id, 'note' => null, 'created_at' => now(), 'updated_at' => now()]];
        DB::table('collection_notes')->insert($rows);
        Schema::drop('user_word_notes');
        $migration = require database_path('migrations/2026_11_14_000002_create_user_word_notes.php');
        $migration->up();
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'newer']);
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $other->id, 'word_id' => $w->id, 'note' => null]);
        $this->assertDatabaseCount('collection_notes', 4);
        $this->assertDatabaseCount('user_word_notes', 2);
    }
}
