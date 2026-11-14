<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_member_lists_are_separately_paginated_names_only_and_collection_scoped(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $other = $owner->collections()->create(['title' => 'Other']);
        for ($i = 0; $i < 17; $i++) {
            $c->members()->attach(User::factory()->create(['name' => sprintf('Member %02d', $i)]));
            $c->blockedUsers()->attach(User::factory()->create(['name' => sprintf('Blocked %02d', $i)]));
        }
        $other->members()->attach(User::factory()->create(['name' => 'Foreign member']));
        $url = '/collections/'.$c->id.'/sharing/members';
        $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $p) => $p->component('Collections/Members')
            ->has('participants.data', 15)->has('blocked.data', 15)->where('participants.total', 17)->where('blocked.total', 17)
            ->where('sharing.participants_count', 17)->where('sharing.blocked_count', 17)
            ->missing('participants.data.0.email')->missing('participants.data.0.pivot')->missing('blocked.data.0.email'));
        $this->get($url.'?participants_page=2')->assertInertia(fn (Assert $p) => $p->has('participants.data', 2)->where('participants.current_page', 2)->where('blocked.current_page', 1)->has('blocked.data', 15));
        $this->get($url.'?blocked_page=2')->assertInertia(fn (Assert $p) => $p->has('blocked.data', 2)->where('participants.current_page', 1));
        $this->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->missing('sharing.participants')->missing('sharing.blocked'));
    }

    public function test_search_filters_both_lists_by_name_and_never_searches_emails(): void
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Main']);
        $c->members()->attach(User::factory()->create(['name' => 'Matching learner', 'email' => 'hidden-person@example.com']));
        $c->members()->attach(User::factory()->create(['name' => 'Unrelated']));
        $c->blockedUsers()->attach(User::factory()->create(['name' => 'Matching blocked']));
        $url = '/collections/'.$c->id.'/sharing/members';
        $this->actingAs($owner)->get($url.'?q=Matching')->assertInertia(fn (Assert $p) => $p->has('participants.data', 1)->has('blocked.data', 1)->where('filters.q', 'Matching')->where('sharing.participants_count', 2));
        $this->getJson($url.'?q[]=bad')->assertUnprocessable();
        $this->getJson($url.'?participants_page=-1')->assertUnprocessable();
        $this->get($url.'?q=hidden-person')->assertInertia(fn (Assert $p) => $p->has('participants.data', 0)->has('blocked.data', 0));
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
}
