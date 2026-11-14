<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class MemberProgressConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['admin_disclosure_version' => 1, 'admin_disclosure_accepted_at' => now()])->save();

        return $u;
    }

    private function fixture(): array
    {
        $owner = $this->user();
        $member = $this->user();
        $c = $owner->collections()->create(['title' => 'Consent collection']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $w = $owner->words()->create(['term' => 'Bright', 'definition' => 'Full of light', 'note' => 'OWNER SECRET']);
        $c->words()->attach($w);

        return [$owner, $member, $c, $w];
    }

    public function test_join_requires_explicit_consent_and_guest_auth_intent_contains_it(): void
    {
        [, $member,$c] = $this->fixture();
        $this->get('/s/'.$c->share_uuid.'/join')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Collections/Consent')->where('existingMember', false));
        $this->postJson('/s/'.$c->share_uuid.'/join')->assertUnprocessable();
        $this->assertDatabaseCount('collection_members', 0);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login')->assertSessionHas('pending_collection_progress_consent', true);
        $member->update(['password' => 'correct-password-123']);
        $this->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/collections/'.$c->id);
        $this->assertNotNull($c->members()->firstOrFail()->pivot->progress_consented_at);
    }

    public function test_old_members_are_gated_on_every_private_read_and_write_path(): void
    {
        [, $member,$c,$w] = $this->fixture();
        $c->members()->attach($member);
        $this->actingAs($member)->get('/collections/'.$c->id)->assertRedirect('/collections/'.$c->id.'/consent');
        foreach (['/words', '/export', '/study', '/words/'.$w->id.'/note'] as $suffix) {
            $this->getJson('/collections/'.$c->id.$suffix)->assertForbidden()->assertJsonPath('needs_consent', true);
        }
        $this->putJson('/collections/'.$c->id.'/words/'.$w->id.'/note', ['note' => 'no'])->assertForbidden();
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study'])->assertForbidden();
        $this->postJson('/collections/'.$c->id.'/sessions/'.Str::uuid().'/complete', ['answers' => []])->assertForbidden();
        $this->get('/s/'.$c->share_uuid.'/study')->assertOk()->assertDontSee('OWNER SECRET');
        $this->get('/collections/'.$c->id.'/consent')->assertOk();
        $this->postJson('/collections/'.$c->id.'/consent', ['progress_consent' => false])->assertUnprocessable();
        $this->post('/collections/'.$c->id.'/consent', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/collections/'.$c->id);
        $this->get('/collections/'.$c->id)->assertOk();
    }

    public function test_progress_only_exposes_current_consented_active_members_and_collection_summary(): void
    {
        [$owner,$member,$c,$w] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $legacy = $this->user();
        $c->members()->attach($legacy);
        $suspended = $this->user();
        $suspended->forceFill(['suspended_at' => now()])->save();
        $c->members()->attach($suspended, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $undisclosed = $this->user();
        $undisclosed->forceFill(['admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null])->save();
        $c->members()->attach($undisclosed, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $c->id, 'mode' => 'test', 'settings' => [], 'questions' => [], 'total_count' => 2, 'correct_count' => 1, 'completed_at' => now()]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'MEMBER PRIVATE NOTE', 'created_at' => now(), 'updated_at' => now()]);
        $session->update(['questions' => [['id' => $w->id, 'prompt' => 'PRIVATE QUESTION']]]);
        $session->answers()->create(['word_id' => $w->id, 'position' => 0, 'question_index' => 0, 'attempt_no' => 1, 'prompt' => 'PRIVATE QUESTION', 'expected' => 'PRIVATE EXPECTED', 'answer' => 'PRIVATE ANSWER', 'correct' => false, 'self_assessed' => false]);
        $other = $owner->collections()->create(['title' => 'Other collection']);
        StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $other->id, 'mode' => 'test', 'settings' => [], 'questions' => [], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $blocked = $this->user();
        $c->members()->attach($blocked, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $c->blockedUsers()->attach($blocked);
        $this->actingAs($owner)->get('/collections/'.$c->id.'/sharing/members?member='.$member->id)->assertOk()->assertInertia(fn (Assert $p) => $p->has('participants.data', 5)->where('selectedMember.id', $member->id)->where('selectedMember.summary.total_sessions', 1)->missing('participants.data.0.email'))->assertDontSee('OWNER SECRET')->assertDontSee('MEMBER PRIVATE NOTE')->assertDontSee('PRIVATE QUESTION')->assertDontSee('PRIVATE EXPECTED')->assertDontSee('PRIVATE ANSWER');
        $this->actingAs($member)->get('/collections/'.$c->id.'/sharing/members')->assertNotFound();
        $this->get('/collections/'.$c->id.'/consent')->assertOk();
        $this->delete('/collections/'.$c->id.'/membership')->assertRedirect('/collections');
        $this->assertNotNull($session->fresh());
        $this->actingAs($owner)->get('/collections/'.$c->id.'/sharing/members?member='.$member->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember', null));
    }

    public function test_declining_preserves_notes_and_history_but_revokes_membership(): void
    {
        [, $member,$c,$w] = $this->fixture();
        $c->members()->attach($member);
        DB::table('collection_notes')->insert(['collection_id' => $c->id, 'user_id' => $member->id, 'word_id' => $w->id, 'note' => 'PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $w->id, 'note' => 'CURRENT PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($member)->delete('/collections/'.$c->id.'/membership')->assertRedirect('/collections');
        $this->assertDatabaseHas('collection_notes', ['user_id' => $member->id, 'note' => 'PRIVATE']);
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $member->id, 'note' => 'CURRENT PRIVATE']);
        $this->get('/collections/'.$c->id)->assertNotFound();
        $this->post('/collections/'.$c->id.'/consent', ['progress_consent' => true, 'consent_version' => 2])->assertNotFound();
    }

    public function test_legacy_pending_join_requires_new_confirmation_and_revoked_link_never_attaches(): void
    {
        [, $member,$c] = $this->fixture();
        $member->update(['password' => 'correct-password-123']);
        $this->withSession(['pending_collection_uuid' => $c->share_uuid])->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/s/'.$c->share_uuid.'/join');
        $this->assertDatabaseCount('collection_members', 0);
        $this->post('/logout');
        $old = $c->share_uuid;
        $c->forceFill(['share_uuid' => null])->save();
        $this->withSession(['pending_collection_uuid' => $old, 'pending_collection_progress_consent' => true])->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_signed_in_only_join_form_requires_login_and_progress_is_paginated(): void
    {
        [$owner,,$c] = $this->fixture();
        $c->forceFill(['share_access' => 'signed_in'])->save();
        $this->get('/s/'.$c->share_uuid.'/join')->assertRedirect('/login')->assertSessionHas('url.intended', '/s/'.$c->share_uuid.'/join');
        for ($i = 0; $i < 17; $i++) {
            $c->members()->attach($this->user(), ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        }
        $this->actingAs($owner)->get('/collections/'.$c->id.'/sharing/members')->assertInertia(fn (Assert $p) => $p->has('participants.data', 15)->where('participants.total', 17));
        $this->get('/collections/'.$c->id.'/sharing/members?participants_page=2')->assertInertia(fn (Assert $p) => $p->has('participants.data', 2));
    }

    public function test_pending_join_waits_for_global_disclosure_without_losing_member_consent(): void
    {
        [, $member,$c] = $this->fixture();
        $member->forceFill(['admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null])->save();
        $member->update(['password' => 'correct-password-123']);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $this->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/account/disclosure')->assertSessionHas('pending_collection_uuid', $c->share_uuid)->assertSessionHas('pending_collection_progress_consent', true);
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_progress_disappears_immediately_on_block_or_unshare(): void
    {
        [$owner,$member,$c] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->actingAs($owner)->get('/collections/'.$c->id.'/sharing/members')->assertInertia(fn (Assert $p) => $p->has('participants.data', 1));
        $this->post('/collections/'.$c->id.'/blocks/'.$member->id)->assertRedirect();
        $this->get('/collections/'.$c->id.'/sharing/members')->assertInertia(fn (Assert $p) => $p->has('participants.data', 0));
        $c->blockedUsers()->detach($member);
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->get('/collections/'.$c->id.'/sharing/members')->assertInertia(fn (Assert $p) => $p->has('participants.data', 0));
        $this->getJson('/collections/'.$c->id.'/sharing/members?participants_page=0')->assertUnprocessable();
    }

    public function test_suspended_login_never_attaches_pending_membership(): void
    {
        [, $member,$c] = $this->fixture();
        $member->forceFill(['suspended_at' => now(), 'password' => 'correct-password-123'])->save();
        $this->withSession(['pending_collection_uuid' => $c->share_uuid, 'pending_collection_progress_consent' => true])->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect();
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_suspended_google_callback_never_attaches_pending_membership(): void
    {
        [, $member,$c] = $this->fixture();
        $member->forceFill(['suspended_at' => now(), 'google_id' => 'suspended-subject'])->save();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'suspended-subject', 'email' => $member->email, 'name' => $member->name]);
        $driver = \Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->withSession(['pending_collection_uuid' => $c->share_uuid, 'pending_collection_progress_consent' => true])->get('/auth/google/callback')->assertRedirect();
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_guest_can_decline_after_back_navigation_and_never_silently_join(): void
    {
        [, $member, $c] = $this->fixture();
        $member->update(['password' => 'correct-password-123']);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $this->post('/s/'.$c->share_uuid.'/join/cancel')->assertRedirect('/s/'.$c->share_uuid)->assertSessionMissing('pending_collection_uuid')->assertSessionMissing('pending_collection_progress_consent');
        $this->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/dashboard');
        $this->assertDatabaseCount('collection_members', 0);
        $this->post('/logout');
        $old = $c->share_uuid;
        $this->post('/s/'.$old.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $c->forceFill(['share_uuid' => null])->save();
        $this->post('/s/'.$old.'/join/cancel')->assertRedirect('/s/'.$old)->assertSessionMissing('pending_collection_uuid')->assertSessionMissing('pending_collection_progress_consent');
    }

    public function test_abandoned_guest_consent_expires_and_requires_new_confirmation(): void
    {
        [, $member, $c] = $this->fixture();
        $member->update(['password' => 'correct-password-123']);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $this->travel(31)->minutes();
        $this->post('/login', ['email' => $member->email, 'password' => 'correct-password-123'])->assertRedirect('/s/'.$c->share_uuid.'/join');
        $this->assertDatabaseCount('collection_members', 0);
    }
}
