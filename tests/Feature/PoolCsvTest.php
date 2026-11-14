<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PoolCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(): array
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $a = $u->wordPools()->create(['name' => 'English', 'language' => 'en']);
        $b = $u->wordPools()->create(['name' => 'Portuguese', 'language' => 'pt']);

        return [$u, $a, $b];
    }

    private function preview($pool, string $text = 'word,new definition')
    {
        return $this->post('/imports/preview', ['pool_id' => $pool->id, 'file' => UploadedFile::fake()->createWithContent('words.csv', "term,definition\n".$text."\n"), 'has_header' => true]);
    }

    public function test_matching_is_only_inside_target_pool_and_no_third_column_is_required(): void
    {
        [$u,$a,$b] = $this->fixture();
        $other = $u->words()->create(['term' => 'word', 'definition' => 'other language']);
        $b->words()->attach($other);
        $legacy = $u->words()->create(['term' => 'word', 'definition' => 'unassigned legacy']);
        $this->preview($a)->assertInertia(fn (Assert $p) => $p->where('preview.created', 1)->where('preview.updated', 0)->where('selectedPoolId', $a->id));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect('/word-pools/'.$a->id);
        $new = $a->words()->firstOrFail();
        $this->assertNotSame($other->id, $new->id);
        $this->assertNotSame($legacy->id, $new->id);
        $this->assertSame('new definition', $new->definition);
        $this->assertSame('other language', $other->fresh()->definition);
        $this->assertSame('unassigned legacy', $legacy->fresh()->definition);
        $this->assertSame(1, $b->words()->count());
    }

    public function test_update_of_reused_word_preserves_pools_notes_links_memberships_and_history(): void
    {
        [$u,$a,$b] = $this->fixture();
        $w = $u->words()->create(['term' => ' Word ', 'definition' => 'old', 'note' => 'owner private']);
        $a->words()->attach($w);
        $b->words()->attach($w);
        $c = $u->collections()->create(['title' => 'Shared']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $uuid = $c->share_uuid;
        $c->words()->attach($w);
        $member = User::factory()->create();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'member private', 'created_at' => now(), 'updated_at' => now()]);
        $s = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $c->id, 'mode' => 'test', 'settings' => [], 'questions' => [['id' => $w->id, 'expected' => 'old']], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $this->preview($a, 'WORD,new definition')->assertInertia(fn (Assert $p) => $p->where('preview.updated', 1));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->assertSame($w->id, $a->words()->first()->id);
        $this->assertSame($w->id, $b->words()->first()->id);
        $this->assertSame('new definition', $b->words()->first()->definition);
        $this->assertSame(' Word ', $w->fresh()->term);
        $this->assertSame('owner private', $w->fresh()->note);
        $this->assertSame($uuid, $c->fresh()->share_uuid);
        $this->assertTrue($c->members()->whereKey($member->id)->exists());
        $this->assertSame($w->id, $c->words()->first()->id);
        $this->assertDatabaseHas('user_word_notes', ['word_id' => $w->id, 'user_id' => $member->id, 'note' => 'member private']);
        $this->assertSame('old', $s->fresh()->questions[0]['expected']);
    }

    public function test_ambiguous_terms_skip_only_in_target_pool(): void
    {
        [$u,$a,$b] = $this->fixture();
        foreach (['Word', 'WORD'] as $term) {
            $w = $u->words()->create(['term' => $term, 'definition' => 'old']);
            $a->words()->attach($w);
        }
        $this->preview($a)->assertInertia(fn (Assert $p) => $p->where('preview.skipped', 1)->has('preview.warnings', 1));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->preview($b)->assertInertia(fn (Assert $p) => $p->where('preview.created', 1));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->assertSame('new definition', $b->words()->first()->definition);
    }

    public function test_commit_rejects_changed_pool_membership_metadata_and_words_atomically(): void
    {
        foreach (['detach', 'attach', 'rename_pool', 'definition'] as $change) {
            [$u,$a] = $this->fixture();
            $w = $u->words()->create(['term' => 'word', 'definition' => 'old']);
            $a->words()->attach($w);
            $this->preview($a, "word,new\nfresh,created")->assertOk();
            if ($change === 'detach') {
                $a->words()->detach($w);
            }
            if ($change === 'attach') {
                $a->words()->attach($u->words()->create(['term' => 'extra', 'definition' => 'other']));
            }
            if ($change === 'rename_pool') {
                $a->update(['name' => 'Renamed']);
            }
            if ($change === 'definition') {
                $w->update(['definition' => 'manual']);
            }
            $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertSessionHasErrors('file');
            $this->assertDatabaseMissing('words', ['user_id' => $u->id, 'term' => 'fresh']);
            $this->assertNotSame('new', $w->fresh()->definition);
        }
    }

    public function test_missing_foreign_deleted_or_switched_target_never_imports(): void
    {
        [$u,$a,$b] = $this->fixture();
        $foreign = User::factory()->create()->wordPools()->create(['name' => 'Private', 'language' => 'en']);
        $this->postJson('/imports/preview', ['file' => UploadedFile::fake()->createWithContent('words.csv', "term,definition\nword,new")])->assertUnprocessable();
        $this->preview($foreign)->assertNotFound();
        $this->preview($a)->assertOk();
        $this->postJson('/imports/store', ['preview_id' => session('csv_preview.preview_id'), 'pool_id' => $b->id])->assertUnprocessable();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id'), 'collection_id' => User::factory()->create()->collections()->create(['title' => 'Foreign'])->id])->assertNotFound();
        $a->delete();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertNotFound();
        $this->assertSame(0, $u->words()->count());
    }

    public function test_export_requires_owned_selected_pool_and_neutralizes_formulas(): void
    {
        [$u,$a,$b] = $this->fixture();
        $w = $u->words()->create(['term' => '=evil', 'definition' => '+formula', 'note' => '@note']);
        $a->words()->attach($w);
        $b->words()->attach($u->words()->create(['term' => 'secret other', 'definition' => 'private']));
        $this->getJson('/words/export')->assertUnprocessable();
        $foreign = User::factory()->create()->wordPools()->create(['name' => 'Foreign', 'language' => 'pt']);
        $this->get('/words/export?pool_id='.$foreign->id)->assertNotFound();
        $csv = $this->get('/words/export?pool_id='.$a->id)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=evil", $csv);
        $this->assertStringContainsString("'+formula", $csv);
        $this->assertStringContainsString("'@note", $csv);
        $this->assertStringNotContainsString('secret other', $csv);
    }

    public function test_preview_keeps_only_hash_of_full_pool_and_ignores_other_pool_drift(): void
    {
        [$u, $a, $b] = $this->fixture();
        for ($i = 0; $i < 25; $i++) {
            $a->words()->attach($u->words()->create(['term' => 'existing '.$i, 'definition' => 'large unchanged content']));
        }
        $this->preview($a)->assertSessionHas('csv_preview', fn ($preview) => ! isset($preview['membership_snapshot']) && strlen($preview['membership_hash']) === 64 && count($preview['rows']) === 1);
        $b->words()->attach($u->words()->create(['term' => 'word', 'definition' => 'other pool change']));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->assertSame(26, $a->words()->count());
        $this->assertSame('other pool change', $b->words()->first()->definition);
    }

    public function test_replaced_preview_cannot_commit_from_another_tab_even_for_same_pool(): void
    {
        [, $a, $b] = $this->fixture();
        foreach ([$b, $a] as $replacementPool) {
            $this->preview($a, 'first,old tab')->assertOk();
            $oldId = session('csv_preview.preview_id');
            $this->preview($replacementPool, 'second,new tab')->assertInertia(fn (Assert $p) => $p->where('preview.preview_id', session('csv_preview.preview_id')));
            $currentId = session('csv_preview.preview_id');
            $this->assertNotSame($oldId, $currentId);
            $this->postJson('/imports/store', ['preview_id' => $oldId])->assertUnprocessable();
            $this->postJson('/imports/store', ['pool_id' => $replacementPool->id])->assertUnprocessable();
            $this->assertDatabaseMissing('words', ['term' => 'first']);
            $this->post('/imports/store', ['preview_id' => $currentId, 'pool_id' => $replacementPool->id])->assertRedirect();
            $count = $replacementPool->words()->count();
            $this->postJson('/imports/store', ['preview_id' => $currentId])->assertUnprocessable();
            $this->assertSame($count, $replacementPool->words()->count());
            $this->travel(61)->seconds();
        }
    }

    public function test_csv_bulk_new_words_inherit_rtl_without_changing_other_pools(): void
    {
        [$u, $rtl, $ltr] = $this->fixture();
        $rtl->update(['text_direction' => 'rtl']);
        $foreign = User::factory()->create()->words()->create(['term' => 'שלום', 'definition' => 'foreign', 'text_direction' => 'ltr']);
        $elsewhere = $u->words()->create(['term' => 'שלום', 'definition' => 'other pool', 'text_direction' => 'ltr']);
        $ltr->words()->attach($elsewhere);
        $this->preview($rtl, "שלום,hello\nمرحبا,welcome")->assertOk();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->assertSame(['rtl', 'rtl'], $rtl->words()->pluck('text_direction')->all());
        $this->assertSame('ltr', $elsewhere->fresh()->text_direction);
        $this->assertSame('other pool', $elsewhere->fresh()->definition);
        $this->assertSame('foreign', $foreign->fresh()->definition);
        $this->assertSame('ltr', $foreign->fresh()->text_direction);
    }

    public function test_pool_or_word_direction_drift_invalidates_preview_atomically(): void
    {
        foreach (['pool', 'word'] as $change) {
            [$u, $pool] = $this->fixture();
            $word = $u->words()->create(['term' => 'word', 'definition' => 'old', 'note' => 'private', 'text_direction' => 'ltr']);
            $pool->words()->attach($word);
            $this->preview($pool, "word,new definition\nfresh,new")->assertOk();
            ($change === 'pool' ? $pool : $word)->update(['text_direction' => 'rtl']);
            $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertSessionHasErrors('file');
            $this->assertSame('old', $word->fresh()->definition);
            $this->assertSame('private', $word->fresh()->note);
            $this->assertDatabaseMissing('words', ['user_id' => $u->id, 'term' => 'fresh']);
        }
    }

    public function test_existing_rtl_shared_word_updates_only_definition_and_inconsistent_target_rejects(): void
    {
        [$u, $a, $b] = $this->fixture();
        $a->update(['text_direction' => 'rtl']);
        $b->update(['text_direction' => 'rtl']);
        $word = $u->words()->create(['term' => 'שלום', 'definition' => 'old', 'note' => 'unchanged', 'text_direction' => 'rtl']);
        $a->words()->attach($word);
        $b->words()->attach($word);
        $this->preview($a, 'שלום,new')->assertOk();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect();
        $this->assertSame('new', $word->fresh()->definition);
        $this->assertSame('unchanged', $word->fresh()->note);
        $this->assertSame('rtl', $b->words()->first()->text_direction);
        // Simulate preexisting inconsistent data: CSV must not silently overwrite direction.
        $word->update(['text_direction' => 'ltr']);
        $this->preview($a, 'שלום,forbidden')->assertOk();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertSessionHasErrors('file');
        $this->assertSame('new', $word->fresh()->definition);
        $this->assertSame('ltr', $word->fresh()->text_direction);
    }
}
