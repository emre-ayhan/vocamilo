<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LiteralSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // LOWER on both sides must work even when SQLite's LIKE is case-sensitive.
        DB::statement('PRAGMA case_sensitive_like = ON');
    }

    protected function tearDown(): void
    {
        DB::statement('PRAGMA case_sensitive_like = OFF');
        parent::tearDown();
    }

    private function progressFixture(): array
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Search']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $this->actingAs($owner);

        return [$owner, $c];
    }

    public function test_progress_is_trimmed_case_insensitive_partial_name_only_and_scoped(): void
    {
        [, $c] = $this->progressFixture();
        $visible = User::factory()->create(['name' => 'ALICE Learner', 'email' => 'hidden-email@example.com']);
        $c->members()->attach($visible, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        foreach (['unconsented', 'suspended', 'blocked', 'global'] as $kind) {
            $user = User::factory()->create(['name' => 'ALICE '.$kind]);
            $c->members()->attach($user, ['progress_consented_at' => $kind === 'unconsented' ? null : now(), 'progress_consent_version' => 2]);
            if ($kind === 'suspended') {
                $user->forceFill(['suspended_at' => now()])->save();
            }
            if ($kind === 'global') {
                $user->forceFill(['admin_disclosure_accepted_at' => null])->save();
            }
            if ($kind === 'blocked') {
                $c->blockedUsers()->attach($user);
            }
        }
        User::factory()->create(['name' => 'ALICE nonmember']);
        $url = '/collections/'.$c->id.'/progress';
        $this->get($url.'?'.http_build_query(['q' => '  LiCe  ']))->assertInertia(fn (Assert $p) => $p->where('filters.q', 'LiCe')->has('members.data', 1)->where('members.data.0.id', $visible->id)->missing('members.data.0.email'));
        $this->get($url.'?q=hidden-email')->assertInertia(fn (Assert $p) => $p->has('members.data', 1)->missing('members.data.0.email'));
    }

    public function test_literal_wildcards_escape_character_and_backslash_do_not_expand_member_search(): void
    {
        [, $c] = $this->progressFixture();
        foreach (['100% learner', 'under_score', 'bang!learner', 'path\\learner', 'Ordinary'] as $name) {
            $c->members()->attach(User::factory()->create(['name' => $name]), ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        }
        foreach (['%', '_', '!', '\\'] as $term) {
            $this->get('/collections/'.$c->id.'/progress?'.http_build_query(['q' => $term]))->assertInertia(fn (Assert $p) => $p->has('members.data', 1));
        }
        $this->assertSame('%!%!_!!\\%', LiteralSearch::pattern('%_!\\'));
    }

    public function test_admin_name_or_email_search_stays_within_consented_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Administrator']);
        $match = User::factory()->create(['name' => 'Other', 'email' => 'Mixed.Case@example.com']);
        User::factory()->create(['name' => 'MIXED Hidden', 'email' => 'hidden-mixed@example.com', 'admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null]);
        $this->actingAs($admin)->get('/admin/users?'.http_build_query(['q' => ' mIxEd.CaSe ']))->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.id', $match->id));
        $this->get('/admin/users?q=mixed')->assertInertia(fn (Assert $p) => $p->has('users.data', 1));
        $literal = User::factory()->create(['name' => 'Only_under']);
        $this->get('/admin/users?q=_')->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.id', $literal->id));
    }

    public function test_admin_collection_title_search_is_literal_and_consent_scoped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $match = $owner->collections()->create(['title' => 'Learning 100%']);
        $owner->collections()->create(['title' => 'Learning 100 words']);
        User::factory()->create(['admin_disclosure_accepted_at' => null])->collections()->create(['title' => 'Learning 100% hidden']);
        $this->actingAs($admin)->get('/admin/collections?'.http_build_query(['q' => ' LEARNING 100% ']))->assertInertia(fn (Assert $p) => $p->has('collections.data', 1)->where('collections.data.0.id', $match->id));
    }

    public function test_unicode_exact_case_matches_without_claiming_sqlite_unicode_case_folding(): void
    {
        [, $c] = $this->progressFixture();
        $user = User::factory()->create(['name' => 'Émre José']);
        $c->members()->attach($user, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->get('/collections/'.$c->id.'/progress?'.http_build_query(['q' => 'Émre']))->assertInertia(fn (Assert $p) => $p->has('members.data', 1));
        // Stock SQLite LOWER is ASCII-only; do not assert É=é without a Unicode extension.
    }

    public function test_filtered_progress_pagination_keeps_query_and_rejects_invalid_filters(): void
    {
        [$owner,$c] = $this->progressFixture();
        for ($i = 0; $i < 17; $i++) {
            $c->members()->attach(User::factory()->create(['name' => sprintf('MATCH learner %02d', $i)]), ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        }
        $c->members()->attach(User::factory()->create(['name' => 'Unrelated']), ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $other = $owner->collections()->create(['title' => 'Other']);
        $other->members()->attach(User::factory()->create(['name' => 'MATCH elsewhere']), ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $url = '/collections/'.$c->id.'/progress';
        $this->get($url.'?q=match')->assertInertia(fn (Assert $p) => $p->has('members.data', 15)->where('members.total', 17)->where('members.next_page_url', fn ($url) => str_contains($url, 'q=match') && str_contains($url, 'page=2')));
        $this->get($url.'?q=match&page=2')->assertInertia(fn (Assert $p) => $p->has('members.data', 2)->where('members.current_page', 2)->where('members.total', 17)->where('filters.q', 'match')->where('members.prev_page_url', fn ($url) => str_contains($url, 'q=match')));
        $this->getJson($url.'?q[]=match')->assertUnprocessable();
        $this->getJson($url.'?q=match&page=-1')->assertUnprocessable();
    }
}
