<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharingMembersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_members_are_deduplicated_paginated_names_only_and_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $other = $owner->collections()->create(['title' => 'Other']);
        for ($i = 0; $i < 17; $i++) {
            $c->members()->attach(User::factory()->create(['name' => sprintf('Member %02d', $i)]));
            $c->blockedUsers()->attach(User::factory()->create(['name' => sprintf('Blocked %02d', $i)]));
        }
        $other->members()->attach(User::factory()->create(['name' => 'Foreign member']));
        $foreign = User::factory()->create()->collections()->create(['title' => 'Foreign']);
        $foreign->members()->attach(User::factory()->create(['name' => 'Private other-owner member']));
        $url = '/collections?tab=members';
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $p) => $p->component('Collections/Index')
            ->has('members.data', 15)->where('members.total', 35)
            ->missing('members.data.0.email')->missing('members.data.0.pivot'));
        $this->get($url.'&members_page=2')->assertInertia(fn (Assert $p) => $p->has('members.data', 15)->where('members.current_page', 2));
        $this->get($url.'&members_page=3')->assertInertia(fn (Assert $p) => $p->has('members.data', 5));
        $this->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->missing('sharing.participants')->missing('sharing.blocked'));
    }

    public function test_search_matches_names_but_hides_nonconsented_email_matches(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $c->members()->attach(User::factory()->create(['name' => 'Matching learner', 'email' => 'hidden-person@example.com']));
        $c->members()->attach(User::factory()->create(['name' => 'Unrelated']));
        $c->blockedUsers()->attach(User::factory()->create(['name' => 'Matching blocked']));
        $url = '/collections?tab=members';
        $this->actingAs($owner)->get($url.'&member_q=Matching')->assertInertia(fn (Assert $p) => $p->has('members.data', 2)->where('memberFilters.q', 'Matching')->where('members.total', 2));
        $this->getJson($url.'&member_q[]=bad')->assertUnprocessable();
        $this->getJson($url.'&members_page=-1')->assertUnprocessable();
        $this->get($url.'&member_q=hidden-person')->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
    }

    public function test_guest_member_blocked_and_unrelated_owner_cannot_access_management(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $member = User::factory()->create();
        $blocked = User::factory()->create();
        $c->members()->attach($member);
        $c->blockedUsers()->attach($blocked);
        $url = '/collections/'.$c->id.'/sharing/members?q=private';
        $this->getJson($url)->assertUnauthorized();
        foreach ([$member, $blocked, User::factory()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertNotFound();
        }
    }

    public function test_stable_name_order_and_only_selected_eligible_member_gets_summary(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $old = User::factory()->create(['name' => 'Z old']);
        $new = User::factory()->create(['name' => 'A new']);
        $tie = User::factory()->create(['name' => 'B tie']);
        foreach ([$old, $new, $tie] as $u) {
            $c->members()->attach($u, ['progress_consented_at' => now(), 'progress_consent_version' => 2, 'created_at' => $u->is($old) ? now()->subDay() : now()]);
        }
        $url = '/collections?tab=members';
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $p) => $p->where('members.data.0.id', $new->id)->where('members.data.1.id', $tie->id)->where('members.data.2.id', $old->id)->missing('members.data.0.summary')->where('selectedMember', null));
        // Selection remains valid even when its row is filtered off the visible page.
        $this->get($url.'&member_q=A+new&member='.$old->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.id', $old->id)->where('selectedMember.summary.total_sessions', 0)->missing('selectedMember.email')->missing('selectedMember.note'));
        $this->getJson($url.'&member[]=1')->assertUnprocessable();
        $this->getJson($url.'&member=0')->assertUnprocessable();
        $this->get($url.'&member=999999')->assertInertia(fn (Assert $p) => $p->where('selectedMember', null));
    }

    public function test_selected_progress_and_email_search_require_every_consent_and_access_condition(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $url = '/collections?tab=members';
        $this->actingAs($owner);
        foreach (['v1', 'missing_date', 'global', 'global_date', 'suspended', 'blocked', 'removed', 'valid'] as $kind) {
            $u = User::factory()->create(['name' => 'Learner '.$kind, 'email' => $kind.'-secret@example.com']);
            $c->members()->attach($u, ['progress_consented_at' => $kind === 'missing_date' ? null : now(), 'progress_consent_version' => $kind === 'v1' ? 1 : 2]);
            if ($kind === 'global') {
                $u->forceFill(['admin_disclosure_version' => null])->save();
            }
            if ($kind === 'global_date') {
                $u->forceFill(['admin_disclosure_accepted_at' => null])->save();
            }
            if ($kind === 'suspended') {
                $u->forceFill(['suspended_at' => now()])->save();
            }
            if ($kind === 'blocked') {
                $c->blockedUsers()->attach($u);
            }
            if ($kind === 'removed') {
                $c->members()->detach($u);
            }
            $response = $this->get($url.'&member_q='.$kind.'-secret&member='.$u->id);
            $response->assertInertia(fn (Assert $p) => $p->has('members.data', $kind === 'valid' ? 1 : 0)->where('members.total', $kind === 'valid' ? 1 : 0));
            if ($kind !== 'valid') {
                $response->assertInertia(fn (Assert $p) => $p->where($kind === 'removed' ? 'selectedMember' : 'selectedMember.summary', null));
            } else {
                $response->assertInertia(fn (Assert $p) => $p->where('selectedMember.id', $u->id)->missing('selectedMember.email'));
            }
        }
        $c->forceFill(['share_uuid' => null])->save();
        $this->get($url.'&member_q=valid-secret&member='.$u->id)->assertInertia(fn (Assert $p) => $p->has('members.data', 0)->where('selectedMember.summary', null));
    }

    public function test_old_progress_url_redirects_only_validated_search_and_page(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $url = '/collections/'.$c->id;
        $this->actingAs($owner)->get($url.'/progress?q=+a%26b+&page=2&unexpected=secret')->assertRedirect($url.'/sharing/members?q=a%26b&participants_page=2');
        $this->getJson($url.'/progress?page=-1')->assertUnprocessable();
        $this->getJson($url.'/progress?q[]=bad')->assertUnprocessable();
        $this->actingAs(User::factory()->create())->get($url.'/progress')->assertNotFound();
    }
}
