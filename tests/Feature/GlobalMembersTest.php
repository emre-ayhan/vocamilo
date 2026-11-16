<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GlobalMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function collection(User $owner): Collection
    {
        $c = $owner->collections()->create(['title' => 'Collection '.Str::random(5)]);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();

        return $c;
    }

    private function join(Collection $c, User $u, int $version = 2): void
    {
        $c->members()->attach($u, ['progress_consented_at' => now(), 'progress_consent_version' => $version]);
    }

    private function preview(User $member, array $data): array
    {
        return $this->postJson('/collection-members/'.$member->id.'/preview', $data)->assertOk()->json();
    }

    private function apply(User $member, array $data, array $preview)
    {
        return $this->postJson('/collection-members/'.$member->id.'/apply', $data + ['token' => $preview['token']]);
    }

    public function test_deduplicated_pagination_and_consent_scoped_email_search(): void
    {
        $owner = User::factory()->create();
        $a = $this->collection($owner);
        $b = $this->collection($owner);
        for ($i = 0; $i < 17; $i++) {
            $u = User::factory()->create(['name' => sprintf('Learner %02d', $i)]);
            $this->join($a, $u);
            $this->join($b, $u);
        }
        $this->actingAs($owner)->get('/collections?tab=members')->assertInertia(fn (Assert $p) => $p->where('members.total', 17)->has('members.data', 15)->missing('members.data.0.email')->missing('members.data.0.pivot'));
        $this->get('/collections?tab=members&members_page=2')->assertInertia(fn (Assert $p) => $p->has('members.data', 2));
        $u = User::factory()->create(['name' => 'Unique', 'email' => 'hiddenneedle@example.com']);
        $this->join($a, $u, 1);
        $foreign = $this->collection(User::factory()->create());
        $this->join($foreign, $u);
        $this->get('/collections?member_q=hiddenneedle')->assertInertia(fn (Assert $p) => $p->where('members.total', 0));
        $this->join($b, $u);
        $this->get('/collections?member_q=hiddenneedle')->assertInertia(fn (Assert $p) => $p->where('members.total', 1)->missing('members.data.0.email'));
        $b->blockedUsers()->attach($u);
        $this->get('/collections?member_q=hiddenneedle')->assertInertia(fn (Assert $p) => $p->where('members.total', 0));
        foreach (['member_q[]=x', 'members_page=0', 'member=-1', 'member_collection[]=x'] as $bad) {
            $this->getJson('/collections?'.$bad)->assertUnprocessable();
        }
    }

    public function test_selected_progress_is_per_collection_and_no_private_payload_is_returned(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $b = $this->collection($owner);
        $this->join($a, $u);
        $this->join($b, $u, 1);
        foreach ([$a, $b] as $c) {
            StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $c->id, 'mode' => 'study', 'settings' => [], 'questions' => [['secret' => 'RAW ANSWER SECRET']], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        }
        $url = '/collections?member='.$u->id.'&member_collection=';
        $response = $this->actingAs($owner)->get($url.$a->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.summary.total_sessions', 1)->has('selectedMember.collections', 2)->missing('selectedMember.email'));
        $response->assertDontSee('RAW ANSWER SECRET');
        $this->get($url.$b->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.summary', null));
        $u->forceFill(['suspended_at' => now()])->save();
        $this->get($url.$a->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.summary', null));
        $foreign = $this->collection(User::factory()->create());
        $this->join($foreign, $u);
        $this->get($url.$foreign->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.summary', null)->where('selectedMember.selected_collection_id', null));
    }

    public function test_all_block_includes_current_unjoined_owned_collections_but_not_other_owners_and_unblock_does_not_rejoin(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $empty = $this->collection($owner);
        $foreign = $this->collection(User::factory()->create());
        $this->join($a, $u);
        $this->join($foreign, $u);
        $word = $owner->words()->create(['term' => 'one', 'definition' => '1', 'note' => 'PRIVATE NOTE']);
        $a->words()->attach($word);
        $history = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $a->id, 'mode' => 'study', 'settings' => [], 'questions' => [], 'total_count' => 1]);
        $data = ['action' => 'block', 'scope' => 'all'];
        $this->actingAs($owner);
        $p = $this->preview($u, $data);
        $this->assertEqualsCanonicalizing([$a->id, $empty->id], $p['collection_ids']);
        $this->assertSame(2, $p['count']);
        $this->apply($u, $data, $p)->assertRedirect();
        foreach ([$a, $empty] as $c) {
            $this->assertTrue($c->isBlocked($u->id));
            $this->assertFalse($c->members()->whereKey($u->id)->exists());
        }
        $this->assertFalse($foreign->isBlocked($u->id));
        $this->assertTrue($foreign->members()->whereKey($u->id)->exists());
        $this->assertSame('PRIVATE NOTE', $word->fresh()->note);
        $this->assertNotNull($history->fresh());
        $data = ['action' => 'unblock', 'scope' => 'all'];
        $this->apply($u, $data, $this->preview($u, $data))->assertRedirect();
        $this->assertFalse($a->isBlocked($u->id));
        $this->assertFalse($a->members()->whereKey($u->id)->exists());
    }

    public function test_stale_confirmation_replay_expiry_and_target_tampering_fail_without_mutation(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $this->join($a, $u);
        $this->actingAs($owner);
        $data = ['action' => 'remove', 'scope' => 'all'];
        $p = $this->preview($u, $data);
        $b = $this->collection($owner);
        $this->join($b, $u);
        $this->apply($u, $data, $p)->assertUnprocessable();
        $this->assertTrue($a->members()->whereKey($u->id)->exists());
        $p = $this->preview($u, $data);
        $this->apply($u, ['action' => 'block', 'scope' => 'all'], $p)->assertUnprocessable();
        $this->apply($u, $data, $p)->assertRedirect();
        $this->join($a, $u);
        $this->join($b, $u);
        $this->apply($u, $data, $p)->assertUnprocessable();
        $this->assertTrue($a->members()->whereKey($u->id)->exists());
        $p = $this->preview($u, $data);
        $this->travel(11)->minutes();
        $this->apply($u, $data, $p)->assertUnprocessable();
    }

    public function test_guests_unrelated_accounts_and_foreign_admin_cannot_manage_or_select_other_owners_participants(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $this->join($a, $u);
        $data = ['action' => 'block', 'scope' => 'all'];
        $this->postJson('/collection-members/'.$u->id.'/preview', $data)->assertUnauthorized();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/collections?member='.$u->id)->assertInertia(fn (Assert $p) => $p->where('members.total', 0)->where('selectedMember', null));
        $this->postJson('/collection-members/'.$u->id.'/preview', $data)->assertNotFound();
        $this->actingAs($owner)->postJson('/collection-members/'.User::factory()->create()->id.'/preview', $data)->assertNotFound();
        $unrelated = $this->collection($owner);
        $this->postJson('/collection-members/'.$u->id.'/preview', ['action' => 'block', 'scope' => 'selected', 'collection_id' => $unrelated->id])->assertNotFound();
    }

    public function test_selected_scope_changes_only_one_collection_and_invalidates_equal_count_swaps(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $b = $this->collection($owner);
        $c = $this->collection($owner);
        $this->join($a, $u);
        $this->join($b, $u);
        $this->actingAs($owner);
        $all = ['action' => 'remove', 'scope' => 'all'];
        $p = $this->preview($u, $all);
        $b->members()->detach($u);
        $this->join($c, $u);
        $this->apply($u, $all, $p)->assertUnprocessable();
        $one = ['action' => 'remove', 'scope' => 'selected', 'collection_id' => $a->id];
        $p = $this->preview($u, $one);
        $this->assertSame(1, $p['count']);
        $this->apply($u, $one, $p)->assertRedirect();
        $this->assertFalse($a->members()->whereKey($u->id)->exists());
        $this->assertTrue($c->members()->whereKey($u->id)->exists());
        $this->actingAs($u)->postJson('/s/'.$a->share_uuid.'/join', ['progress_consent' => true])->assertUnprocessable();
        $this->post('/s/'.$a->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect();
        $this->assertSame(2, $a->members()->whereKey($u->id)->firstOrFail()->pivot->progress_consent_version);
    }

    public function test_all_email_and_progress_eligibility_conditions_fail_closed(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create(['name' => 'Plain learner', 'email' => 'private-email-only@example.com']);
        $a = $this->collection($owner);
        $this->join($a, $u);
        $this->actingAs($owner);
        $url = '/collections?member_q=private-email-only&member='.$u->id.'&member_collection='.$a->id;
        foreach (['global', 'timestamp', 'suspended', 'consent', 'share', 'blocked'] as $condition) {
            $u->forceFill(['admin_disclosure_version' => 1, 'admin_disclosure_accepted_at' => now(), 'suspended_at' => null])->save();
            $a->forceFill(['share_uuid' => (string) Str::uuid()])->save();
            $a->members()->updateExistingPivot($u->id, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
            $a->blockedUsers()->detach($u);
            if ($condition === 'global') {
                $u->forceFill(['admin_disclosure_version' => null])->save();
            }
            if ($condition === 'timestamp') {
                $u->forceFill(['admin_disclosure_accepted_at' => null])->save();
            }
            if ($condition === 'suspended') {
                $u->forceFill(['suspended_at' => now()])->save();
            }
            if ($condition === 'consent') {
                $a->members()->updateExistingPivot($u->id, ['progress_consented_at' => null]);
            }
            if ($condition === 'share') {
                $a->forceFill(['share_uuid' => null])->save();
            }
            if ($condition === 'blocked') {
                $a->blockedUsers()->attach($u);
            }
            $this->get($url)->assertInertia(fn (Assert $p) => $p->where('members.total', 0)->where('selectedMember.summary', null));
        }
    }

    public function test_lost_pending_nonce_requires_a_new_confirmation(): void
    {
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $this->join($a, $u);
        $this->actingAs($owner);
        $data = ['action' => 'remove', 'scope' => 'all'];
        $p = $this->preview($u, $data);
        Cache::flush();
        $this->apply($u, $data, $p)->assertUnprocessable();
        $this->assertTrue($a->members()->whereKey($u->id)->exists());
    }

    public function test_remove_hides_history_until_fresh_consent_without_deleting_notes_or_sessions(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $u = User::factory()->create();
        $a = $this->collection($owner);
        $this->join($a, $u);
        $word = $owner->words()->create(['term' => 'one', 'definition' => '1', 'note' => 'OWNER PRIVATE']);
        $a->words()->attach($word);
        DB::table('user_word_notes')->insert(['user_id' => $u->id, 'word_id' => $word->id, 'note' => 'LEARNER PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
        $history = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $a->id, 'mode' => 'study', 'settings' => [], 'questions' => [], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $this->actingAs($owner);
        $data = ['action' => 'remove', 'scope' => 'all'];
        $p = $this->preview($u, $data);
        $this->apply($u, $data, $p)->assertRedirect();
        $url = '/collections?member='.$u->id.'&member_collection='.$a->id;
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('selectedMember', null));
        $this->assertNotNull($history->fresh());
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $u->id, 'word_id' => $word->id, 'note' => 'LEARNER PRIVATE']);
        $this->actingAs($u)->post('/s/'.$a->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect();
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('selectedMember.summary.total_sessions', 1))->assertDontSee('LEARNER PRIVATE')->assertDontSee('OWNER PRIVATE');
        // Identical membership timestamps are deliberate: the one-use nonce must still stop replay.
        $this->apply($u, $data, $p)->assertUnprocessable();
        $this->assertTrue($a->members()->whereKey($u->id)->exists());
    }
}
