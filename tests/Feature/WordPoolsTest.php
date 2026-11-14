<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WordPoolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_migration_never_assigns_legacy_words_and_pool_is_required_for_new_words(): void
    {
        $u = User::factory()->create();
        $w = $u->words()->create(['term' => 'bank', 'definition' => 'river edge', 'note' => 'private']);
        $this->assertDatabaseCount('word_pools', 0);
        $this->assertDatabaseCount('pool_word', 0);
        $this->actingAs($u)->get('/words')->assertInertia(fn (Assert $p) => $p->component('Words/Pools')->has('pools', 0)->missing('words')->missing('selectedPool'));
        $this->postJson('/words', ['term' => 'bank', 'definition' => 'financial'])->assertUnprocessable();
        $this->post('/word-pools', ['name' => 'English', 'language' => 'en-GB'])->assertRedirect();
        $pool = $u->wordPools()->first();
        $this->post('/words', ['pool_id' => $pool->id, 'term' => 'bank', 'definition' => 'financial'])->assertRedirect();
        $this->assertDatabaseCount('words', 2);
        $this->assertSame(1, $pool->words()->count());
        $this->assertSame('private', $w->fresh()->note);
    }

    public function test_reusing_and_detaching_preserves_identity_notes_and_collection_links(): void
    {
        $u = User::factory()->create();
        $a = $u->wordPools()->create(['name' => 'A', 'language' => 'en']);
        $b = $u->wordPools()->create(['name' => 'B', 'language' => 'pt-PT']);
        $w = $u->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'private']);
        $a->words()->attach($w);
        $c = $u->collections()->create(['title' => 'C']);
        $c->words()->attach($w);
        $this->actingAs($u)->post('/word-pools/'.$b->id.'/words', ['word_ids' => [$w->id]])->assertRedirect();
        $this->put('/words/'.$w->id, ['term' => 'word', 'definition' => 'updated', 'note' => 'private'])->assertRedirect();
        $this->assertSame('updated', $b->words()->first()->definition);
        $this->assertSame(1, $u->words()->count());
        $this->delete('/word-pools/'.$a->id.'/words/'.$w->id)->assertRedirect();
        $this->delete('/word-pools/'.$b->id)->assertRedirect('/words');
        $this->assertSame('private', $w->fresh()->note);
        $this->assertSame(1, $c->words()->count());
        $this->assertSame(0, $w->pools()->count());
    }

    public function test_pool_and_word_access_are_owner_scoped_and_reuse_lookup_paginated(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $p = $u->wordPools()->create(['name' => 'Mine', 'language' => 'en']);
        $foreign = $other->wordPools()->create(['name' => 'Foreign', 'language' => 'pt']);
        $word = $other->words()->create(['term' => 'secret', 'definition' => 'hidden']);
        for ($i = 0; $i < 32; $i++) {
            $u->words()->create(['term' => 'term'.$i, 'definition' => 'meaning']);
        }
        $this->actingAs($u)->getJson('/word-pools/'.$p->id.'/available-words')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('total', 32)->assertDontSee('secret');
        $this->get('/words?pool_id='.$foreign->id)->assertNotFound();
        $this->post('/word-pools/'.$p->id.'/words', ['word_ids' => [$word->id]])->assertUnprocessable();
        $this->put('/word-pools/'.$foreign->id, ['name' => 'Bad', 'language' => 'en'])->assertNotFound();
        $this->delete('/word-pools/'.$foreign->id)->assertNotFound();
        $this->post('/words', ['pool_id' => $foreign->id, 'term' => 'bad', 'definition' => 'bad'])->assertNotFound();
    }

    public function test_collection_candidates_and_attach_are_scoped_to_selected_owned_pools_and_voice_is_owner_only(): void
    {
        $u = User::factory()->create();
        $a = $u->wordPools()->create(['name' => 'A', 'language' => 'en']);
        $b = $u->wordPools()->create(['name' => 'B', 'language' => 'pt']);
        $wa = $u->words()->create(['term' => 'same', 'definition' => 'A']);
        $wb = $u->words()->create(['term' => 'same', 'definition' => 'B']);
        $legacy = $u->words()->create(['term' => 'legacy', 'definition' => 'L']);
        $a->words()->attach($wa);
        $b->words()->attach($wb);
        $c = $u->collections()->create(['title' => 'C']);
        $this->actingAs($u)->get('/collections/'.$c->id.'?pool_ids[]='.$a->id)->assertInertia(fn (Assert $p) => $p->has('availableWords', 1)->where('availableWords.0.id', $wa->id));
        $this->post('/collections/'.$c->id.'/words', ['pool_ids' => [$a->id], 'word_ids' => [$wb->id]])->assertUnprocessable();
        $this->post('/collections/'.$c->id.'/words', ['word_ids' => [$legacy->id]])->assertUnprocessable();
        $this->post('/collections/'.$c->id.'/words', ['pool_ids' => [$a->id, $b->id], 'word_ids' => [$wa->id, $wb->id]])->assertRedirect();
        $this->put('/collections/'.$c->id, ['title' => 'C', 'voice_language' => 'pt-PT'])->assertRedirect();
        $this->assertSame('pt-PT', $c->fresh()->voice_language);
        $this->actingAs(User::factory()->create())->put('/collections/'.$c->id, ['title' => 'bad', 'voice_language' => 'en'])->assertNotFound();
    }

    public function test_legacy_assignment_is_exact_account_dry_run_then_explicit_idempotent_apply(): void
    {
        $u = User::factory()->create(['email' => 'exact@example.com']);
        $p = $u->wordPools()->create(['name' => 'Legacy', 'language' => 'en']);
        $other = User::factory()->create();
        $other->words()->create(['term' => 'other', 'definition' => 'other']);
        $w = $u->words()->create(['term' => 'old', 'definition' => 'original', 'note' => 'note']);
        $member = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Existing collection']);
        $c->forceFill(['share_uuid' => (string) Str::uuid(), 'share_access' => 'signed_in'])->save();
        $c->words()->attach($w, ['added_at' => now()->subDays(2)]);
        $c->members()->attach($member, ['progress_consented_at' => now()->subDay(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'member private', 'created_at' => now(), 'updated_at' => now()]);
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $c->id, 'mode' => 'study', 'settings' => [], 'questions' => [['id' => $w->id]], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $session->answers()->create(['word_id' => $w->id, 'position' => 0, 'prompt' => 'old', 'expected' => 'original', 'answer' => 'original', 'correct' => true]);
        $tables = ['words', 'collections', 'collection_word', 'collection_members', 'user_word_notes', 'study_sessions', 'study_answers'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->toJson();
        }
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $u->email, 'pool' => $p->id])->assertSuccessful();
        $this->assertDatabaseCount('pool_word', 0);
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $other->email, 'pool' => $p->id, '--apply' => true])->assertFailed();
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $u->email, 'pool' => $p->id, '--apply' => true])->assertSuccessful();
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $u->email, 'pool' => $p->id, '--apply' => true])->assertSuccessful();
        foreach ($tables as $table) {
            $this->assertSame($snapshot[$table], DB::table($table)->get()->toJson(), $table.' must remain byte-for-byte unchanged');
        }
        $this->assertDatabaseCount('pool_word', 1);
        $this->assertSame($w->id, $p->words()->first()->id);
        $this->assertSame('note', $w->fresh()->note);
    }

    public function test_actual_pool_migration_preserves_preexisting_shared_collection_data(): void
    {
        $migration = require database_path('migrations/2026_11_14_000007_create_word_pools.php');
        $migration->down();
        $u = User::factory()->create(['email' => 'exact@example.com']);
        $other = User::factory()->create();
        $other->words()->create(['term' => 'other', 'definition' => 'other']);
        $w = $u->words()->create(['term' => 'old', 'definition' => 'original', 'note' => 'note']);
        $member = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Existing collection']);
        $c->forceFill(['share_uuid' => (string) Str::uuid(), 'share_access' => 'signed_in'])->save();
        $c->words()->attach($w, ['added_at' => now()->subDays(2)]);
        $c->members()->attach($member, ['progress_consented_at' => now()->subDay(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'member private', 'created_at' => now(), 'updated_at' => now()]);
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $c->id, 'mode' => 'study', 'settings' => [], 'questions' => [['id' => $w->id]], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $session->answers()->create(['word_id' => $w->id, 'position' => 0, 'prompt' => 'old', 'expected' => 'original', 'answer' => 'original', 'correct' => true]);
        $tables = ['words', 'collections', 'collection_word', 'collection_members', 'user_word_notes', 'study_sessions', 'study_answers'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->toJson();
        }
        $migration->up();
        foreach ($tables as $table) {
            $rows = DB::table($table)->get();
            // The only new field on existing rows is nullable voice_language.
            if ($table === 'collections') {
                $rows->each(function ($row) {
                    $this->assertNull($row->voice_language);
                    unset($row->voice_language);
                });
            }
            $this->assertSame($snapshot[$table], $rows->toJson(), $table.' existing data must survive migration unchanged');
        }
        $this->assertDatabaseCount('word_pools', 0);
        $this->assertDatabaseCount('pool_word', 0);
        $this->actingAs($member)->get('/collections/'.$c->id)->assertOk();
        $this->get('/s/'.$c->share_uuid)->assertOk();
    }

    public function test_overview_has_only_owned_pool_cards_and_detail_is_scoped_paginated_and_searchable(): void
    {
        $u = User::factory()->create();
        $a = $u->wordPools()->create(['name' => 'A', 'language' => 'en']);
        $b = $u->wordPools()->create(['name' => 'B', 'language' => 'pt']);
        $foreign = User::factory()->create()->wordPools()->create(['name' => 'Hidden pool', 'language' => 'fr']);
        for ($i = 0; $i < 32; $i++) {
            $w = $u->words()->create(['term' => 'Match '.$i, 'definition' => 'private definition', 'note' => 'private note']);
            $a->words()->attach($w);
        }
        $hidden = $u->words()->create(['term' => 'Different', 'definition' => 'other pool only']);
        $b->words()->attach($hidden);
        $this->actingAs($u)->get('/words')->assertInertia(fn (Assert $p) => $p->component('Words/Pools')->has('pools', 2)->where('pools.0.words_count', 32)->missing('words')->missing('selectedPool'))->assertDontSee('private definition')->assertDontSee('private note')->assertDontSee('Hidden pool');
        $this->get('/word-pools/'.$a->id.'?search=Match&page=2')->assertInertia(fn (Assert $p) => $p->component('Words/Index')->where('selectedPool.id', $a->id)->has('words.data', 2)->where('words.total', 32)->where('words.current_page', 2))->assertDontSee('other pool only');
        $this->get('/word-pools/'.$foreign->id)->assertNotFound();
        $this->getJson('/word-pools/'.$a->id.'?search[]=bad')->assertUnprocessable();
        $this->getJson('/word-pools/'.$a->id.'?page=0')->assertUnprocessable();
        $this->get('/words?pool_id='.$a->id.'&search=a%26b&page=2&unsafe=ignore')->assertRedirect('/word-pools/'.$a->id.'?search=a%26b&page=2');
        $this->post('/word-pools', ['name' => 'New', 'language' => 'en'])->assertRedirect('/word-pools/'.$u->wordPools()->latest('id')->first()->id);
    }
}
