<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CollectionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SharingControlsTest extends TestCase
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
        $member = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Shared']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $w = $owner->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'PRIVATE']);
        $c->words()->attach($w);
        $c->members()->attach($member);

        return [$owner, $member, $c->fresh(), $w];
    }

    public function test_defaults_allow_guests_and_signed_in_gate_applies_to_all_bearer_reads(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $this->assertSame('guests', $c->share_access);
        $this->get('/s/'.$c->share_uuid)->assertOk();
        $this->actingAs($owner)->put('/collections/'.$c->id.'/sharing/access', ['access' => 'signed_in'])->assertRedirect();
        $this->post('/logout');
        foreach (['', '/study?mode=study', '/study?mode=test', '/study?mode=flashcards', '/words'] as $suffix) {
            $this->get('/s/'.$c->share_uuid.$suffix)->assertRedirect('/login');
            $this->getJson('/s/'.$c->share_uuid.$suffix)->assertUnauthorized();
        }
        $this->post('/s/'.$c->share_uuid.'/join')->assertRedirect('/login')->assertSessionHas('pending_collection_uuid', $c->share_uuid);
        $this->actingAs($member)->get('/s/'.$c->share_uuid.'/study')->assertOk()->assertDontSee('PRIVATE');
    }

    public function test_rotate_keeps_members_but_invalidates_old_link_and_remove_allows_rejoin(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $old = $c->share_uuid;
        $this->actingAs($owner)->post('/collections/'.$c->id.'/sharing/rotate')->assertRedirect();
        $new = $c->fresh()->share_uuid;
        $this->assertNotSame($old, $new);
        $this->assertSame(1, $c->members()->count());
        $this->get('/s/'.$old)->assertNotFound();
        $this->actingAs($member)->get('/collections/'.$c->id)->assertOk();
        $this->actingAs($owner)->delete('/collections/'.$c->id.'/members/'.$member->id)->assertRedirect();
        $this->actingAs($member)->get('/collections/'.$c->id)->assertNotFound();
        $this->post('/s/'.$new.'/join')->assertRedirect('/collections/'.$c->id);
    }

    public function test_block_survives_rotation_and_unshare_and_denies_every_account_access_path(): void
    {
        [$owner, $member, $c, $w] = $this->fixture();
        $session = $this->actingAs($member)->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'flashcards', 'settings' => ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
        $this->actingAs($owner)->post('/collections/'.$c->id.'/blocks/'.$member->id)->assertRedirect();
        $this->post('/collections/'.$c->id.'/sharing/rotate')->assertRedirect();
        $this->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->post('/collections/'.$c->id.'/sharing')->assertRedirect();
        $uuid = $c->fresh()->share_uuid;
        // Even a stale membership must not override an explicit block.
        $c->members()->attach($member);
        $this->actingAs($member);
        foreach (['', '/study?mode=flashcards', '/study?mode=study', '/study?mode=test', '/words'] as $suffix) {
            $this->get('/s/'.$uuid.$suffix)->assertNotFound();
        }
        foreach (['', '/study', '/words', '/export', '/words/'.$w->id.'/note'] as $suffix) {
            $this->getJson('/collections/'.$c->id.$suffix)->assertNotFound();
        }
        $this->post('/s/'.$uuid.'/join')->assertNotFound();
        $this->putJson('/collections/'.$c->id.'/words/'.$w->id.'/note', ['note' => 'blocked'])->assertNotFound();
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study'])->assertNotFound();
        $this->postJson('/collections/'.$c->id.'/sessions/'.$session['id'].'/complete', ['answers' => [['word_id' => $w->id, 'known' => true]]])->assertNotFound();
        $this->get('/collections')->assertInertia(fn (Assert $p) => $p->has('collections', 0));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.collections', 0)->where('stats.joined', 0));
    }

    public function test_management_is_owner_only_and_participant_details_do_not_leak(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $this->actingAs($owner)->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('sharing.access', 'guests')->where('sharing.participants_count', 1)->missing('sharing.participants')->missing('sharing.blocked'));
        $this->actingAs($member)->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->missing('sharing'));
        $this->put('/collections/'.$c->id.'/sharing/access', ['access' => 'signed_in'])->assertNotFound();
        $this->post('/collections/'.$c->id.'/sharing/rotate')->assertNotFound();
        $this->delete('/collections/'.$c->id.'/members/'.$owner->id)->assertNotFound();
        $this->post('/collections/'.$c->id.'/blocks/'.$owner->id)->assertNotFound();
        $this->delete('/collections/'.$c->id.'/blocks/'.$owner->id)->assertNotFound();
        $this->actingAs($owner)->put('/collections/'.$c->id.'/sharing/access', ['access' => 'invalid'])->assertSessionHasErrors('access');
        $this->postJson('/collections/'.$c->id.'/blocks/'.$owner->id)->assertUnprocessable();
    }

    public function test_pending_join_cannot_bypass_block_and_unblock_does_not_restore_membership(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $this->actingAs($owner)->post('/collections/'.$c->id.'/blocks/'.$member->id)->assertRedirect();
        $this->actingAs($member)->withSession(['pending_collection_uuid' => $c->share_uuid]);
        $request = request();
        $request->setUserResolver(fn () => $member);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('pending_collection_uuid', $c->share_uuid);
        $this->assertNull(CollectionAccess::finishPendingJoin($request));
        $this->assertFalse($c->members()->where('users.id', $member->id)->exists());
        $this->actingAs($owner)->delete('/collections/'.$c->id.'/blocks/'.$member->id)->assertRedirect();
        $this->assertSame(0, $c->members()->count());
        $this->actingAs($member)->post('/s/'.$c->share_uuid.'/join')->assertRedirect('/collections/'.$c->id);
    }

    public function test_signed_in_link_preserves_intended_destination_after_registration(): void
    {
        [, , $c] = $this->fixture();
        $c->forceFill(['share_access' => 'signed_in'])->save();
        $url = '/s/'.$c->share_uuid.'/study?mode=test';
        $this->get($url)->assertRedirect('/login');
        $this->post('/register', ['name' => 'New learner', 'email' => 'new-learner@example.com', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertRedirect($url);
    }

    public function test_rotate_requires_active_share_and_old_pending_intent_cannot_rejoin(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $old = $c->share_uuid;
        $this->actingAs($owner)->post('/collections/'.$c->id.'/sharing/rotate')->assertRedirect();
        $this->post('/s/'.$old.'/join')->assertNotFound();
        $this->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->postJson('/collections/'.$c->id.'/sharing/rotate')->assertUnprocessable();
        $this->assertNull($c->fresh()->share_uuid);
        $this->assertSame(0, $c->members()->count());
    }

    public function test_google_returns_to_gated_public_page_without_implicit_membership_or_external_redirect(): void
    {
        [, , $c] = $this->fixture();
        $c->forceFill(['share_access' => 'signed_in'])->save();
        $url = '/s/'.$c->share_uuid.'/study?mode=study';
        $this->get($url)->assertRedirect('/login');
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $google = (new \Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'gated-subject', 'email' => 'gated@gmail.com', 'name' => 'Learner']);
        $driver = \Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->get('/auth/google/callback?next=https://evil.example')->assertRedirect($url);
        $this->assertSame(1, $c->members()->count());
        $this->get($url)->assertOk()->assertDontSee('PRIVATE');
    }

    public function test_access_mode_toggle_keeps_existing_members_and_new_guest_behavior(): void
    {
        [$owner,$member,$c] = $this->fixture();
        $this->actingAs($owner)->put('/collections/'.$c->id.'/sharing/access', ['access' => 'signed_in'])->assertRedirect();
        $this->actingAs($member)->get('/collections/'.$c->id)->assertOk();
        $this->actingAs($owner)->put('/collections/'.$c->id.'/sharing/access', ['access' => 'guests'])->assertRedirect();
        $this->assertSame(1, $c->members()->count());
        $this->post('/logout');
        $this->get('/s/'.$c->share_uuid)->assertOk();
    }
}
