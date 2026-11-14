<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberConsentVersionTest extends TestCase
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
        $member = User::factory()->create(['name' => 'Learner', 'email' => 'private.unique@example.com']);
        $c = $owner->collections()->create(['title' => 'Versioned']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 1]);

        return [$owner, $member, $c];
    }

    public function test_v1_members_are_hidden_even_when_email_matches_then_reconsent_enables_search(): void
    {
        [$owner,$member,$c] = $this->fixture();
        $this->actingAs($owner)->get('/collections/'.$c->id.'/progress?q=PRIVATE.UNI')->assertInertia(fn (Assert $p) => $p->has('members.data', 0)->where('members.total', 0));
        $this->actingAs($member)->get('/collections/'.$c->id)->assertRedirect('/collections/'.$c->id.'/consent');
        $this->get('/collections/'.$c->id.'/consent')->assertInertia(fn (Assert $p) => $p->where('consentVersion', 2));
        foreach ([['progress_consent' => true], ['progress_consent' => true, 'consent_version' => 1]] as $stale) {
            $this->postJson('/collections/'.$c->id.'/consent', $stale)->assertUnprocessable();
        }
        $this->assertSame(1, (int) $c->members()->first()->pivot->progress_consent_version);
        $this->post('/collections/'.$c->id.'/consent', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/collections/'.$c->id);
        $this->actingAs($owner)->get('/collections/'.$c->id.'/progress?q=PRIVATE.UNI')->assertInertia(fn (Assert $p) => $p->has('members.data', 1)->where('members.data.0.id', $member->id)->missing('members.data.0.email'))->assertDontSee($member->email);
    }

    public function test_email_or_search_cannot_escape_membership_consent_or_suspension_filters(): void
    {
        [$owner,$member,$c] = $this->fixture();
        $c->members()->updateExistingPivot($member->id, ['progress_consent_version' => 2]);
        User::factory()->create(['email' => 'foreign.unique@example.com']);
        $hidden = User::factory()->create(['email' => 'legacy.unique@example.com']);
        $c->members()->attach($hidden, ['progress_consented_at' => now(), 'progress_consent_version' => 1]);
        $suspended = User::factory()->create(['email' => 'suspended.unique@example.com', 'suspended_at' => now()]);
        $c->members()->attach($suspended, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $blocked = User::factory()->create(['email' => 'blocked.unique@example.com']);
        $c->members()->attach($blocked, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $c->blockedUsers()->attach($blocked);
        $this->actingAs($owner)->get('/collections/'.$c->id.'/progress?q=unique')->assertInertia(fn (Assert $p) => $p->has('members.data', 1)->where('members.total', 1));
        $this->get('/collections/'.$c->id.'/progress?q=%25')->assertInertia(fn (Assert $p) => $p->has('members.data', 0));
    }

    public function test_old_cached_join_form_and_pending_consent_never_upgrade_silently(): void
    {
        [, $member,$c] = $this->fixture();
        $c->members()->detach();
        $member->update(['password' => 'strong-password-123']);
        $this->postJson('/s/'.$c->share_uuid.'/join', ['progress_consent' => true])->assertUnprocessable();
        $this->postJson('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 1])->assertUnprocessable();
        $this->withSession(['pending_collection_uuid' => $c->share_uuid, 'pending_collection_progress_consent' => true, 'pending_collection_consent_version' => 1, 'pending_collection_consent_expires_at' => now()->addMinutes(20)->timestamp])->post('/login', ['email' => $member->email, 'password' => 'strong-password-123'])->assertRedirect('/s/'.$c->share_uuid.'/join');
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_new_guest_consent_has_version_and_cancel_clears_it(): void
    {
        [,,$c] = $this->fixture();
        $c->members()->detach();
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertSessionHas('pending_collection_consent_version', 2);
        $this->post('/s/'.$c->share_uuid.'/join/cancel')->assertSessionMissing('pending_collection_consent_version');
    }

    public function test_unversioned_fresh_pending_consent_requires_confirmation(): void
    {
        [, $member, $c] = $this->fixture();
        $c->members()->detach();
        $member->update(['password' => 'strong-password-123']);
        $this->withSession(['pending_collection_uuid' => $c->share_uuid, 'pending_collection_progress_consent' => true, 'pending_collection_consent_expires_at' => now()->addMinutes(20)->timestamp])->post('/login', ['email' => $member->email, 'password' => 'strong-password-123'])->assertRedirect('/s/'.$c->share_uuid.'/join');
        $this->assertDatabaseCount('collection_members', 0);
    }
}
