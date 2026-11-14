<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TextDirectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_create_inherits_direction_and_detach_delete_keeps_word_direction_in_collection(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->post('/word-pools', ['name' => 'Arabic', 'language' => 'ar', 'text_direction' => 'rtl'])->assertRedirect();
        $p = $u->wordPools()->first();
        $this->post('/words', ['pool_id' => $p->id, 'term' => 'كتاب', 'definition' => 'book', 'text_direction' => 'ltr'])->assertRedirect();
        $w = $u->words()->first();
        $this->assertSame('rtl', $w->text_direction);
        $c = $u->collections()->create(['title' => 'Mixed']);
        $c->words()->attach($w);
        $this->delete('/word-pools/'.$p->id.'/words/'.$w->id)->assertRedirect();
        $this->delete('/word-pools/'.$p->id)->assertRedirect();
        $this->assertSame('rtl', $w->fresh()->text_direction);
        $this->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('collection.words.0.text_direction', 'rtl'));
    }

    public function test_reuse_rejects_cross_direction_atomically_but_unpooled_adopts_target(): void
    {
        $u = User::factory()->create();
        $ltr = $u->wordPools()->create(['name' => 'L', 'language' => 'en']);
        $rtl = $u->wordPools()->create(['name' => 'R', 'language' => 'ar', 'text_direction' => 'rtl']);
        $w = $u->words()->create(['term' => 'x', 'definition' => 'y']);
        $ltr->words()->attach($w);
        $unpooled = $u->words()->create(['term' => 'a', 'definition' => 'b']);
        $this->actingAs($u)->postJson('/word-pools/'.$rtl->id.'/words', ['word_ids' => [$unpooled->id, $w->id]])->assertUnprocessable();
        $this->assertSame(0, $rtl->words()->count());
        $this->assertSame('ltr', $unpooled->fresh()->text_direction);
        $this->post('/word-pools/'.$rtl->id.'/words', ['word_ids' => [$unpooled->id]])->assertRedirect();
        $this->assertSame('rtl', $unpooled->fresh()->text_direction);
        $this->put('/words/'.$unpooled->id, ['term' => 'a', 'definition' => 'b', 'text_direction' => 'ltr'])->assertRedirect();
        $this->assertSame('rtl', $unpooled->fresh()->text_direction);
    }

    public function test_edit_direction_checks_all_other_pools_and_rolls_back_metadata_then_updates_all_words(): void
    {
        $u = User::factory()->create();
        $a = $u->wordPools()->create(['name' => 'A', 'language' => 'en']);
        $b = $u->wordPools()->create(['name' => 'B', 'language' => 'en']);
        $w = $u->words()->create(['term' => 'a', 'definition' => 'b']);
        $a->words()->attach($w);
        $b->words()->attach($w);
        $this->actingAs($u)->putJson('/word-pools/'.$a->id, ['name' => 'Changed', 'language' => 'ar', 'text_direction' => 'rtl'])->assertUnprocessable();
        $this->assertSame('A', $a->fresh()->name);
        $this->assertSame('ltr', $w->fresh()->text_direction);
        $b->words()->detach($w);
        $this->put('/word-pools/'.$a->id, ['name' => 'Changed', 'language' => 'ar', 'text_direction' => 'rtl'])->assertRedirect();
        $this->assertSame('rtl', $w->fresh()->text_direction);
        $this->put('/word-pools/'.$a->id, ['name' => 'Renamed', 'language' => 'ar'])->assertRedirect();
        $this->assertSame('rtl', $a->fresh()->text_direction);
        $this->putJson('/word-pools/'.$a->id, ['name' => 'Bad', 'language' => 'ar', 'text_direction' => 'auto'])->assertUnprocessable();
    }

    public function test_legacy_command_adopts_only_target_direction_and_dry_run_never_mutates(): void
    {
        $u = User::factory()->create();
        $p = $u->wordPools()->create(['name' => 'Arabic', 'language' => 'ar', 'text_direction' => 'rtl']);
        $w = $u->words()->create(['term' => 'word', 'definition' => 'meaning']);
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $u->email, 'pool' => $p->id])->assertSuccessful();
        $this->assertSame('ltr', $w->fresh()->text_direction);
        $this->artisan('vocamilo:assign-legacy-words', ['email' => $u->email, 'pool' => $p->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('rtl', $w->fresh()->text_direction);
    }

    public function test_direction_migration_defaults_legacy_records_without_changing_shared_data(): void
    {
        $migration = require database_path('migrations/2026_11_14_000008_add_text_direction.php');
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
        $pool = $u->wordPools()->create(['name' => 'Existing pool', 'language' => 'ar']);
        $pool->words()->attach($w);
        $tables = ['word_pools', 'pool_word', 'words', 'collections', 'collection_word', 'collection_members', 'user_word_notes', 'study_sessions', 'study_answers'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->toJson();
        }
        $migration->up();
        foreach ($tables as $table) {
            $rows = DB::table($table)->get();
            if (in_array($table, ['words', 'word_pools'], true)) {
                $rows->each(function ($row) {
                    $this->assertSame('ltr', $row->text_direction);
                    unset($row->text_direction);
                });
            }
            $this->assertSame($snapshot[$table], $rows->toJson(), $table.' existing data must remain unchanged');
        }
        $this->actingAs($member)->get('/collections/'.$c->id)->assertOk();
        $this->get('/s/'.$c->share_uuid)->assertOk();
    }
}
