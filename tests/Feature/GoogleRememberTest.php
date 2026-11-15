<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class GoogleRememberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.secure' => true, 'session.same_site' => 'lax', 'services.google.client_id' => 'client', 'services.google.client_secret' => 'secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    }

    private function provider(string $email = 'google@example.com', string $subject = 'remember-subject'): void
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'fake-token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['sub' => $subject, 'email' => $email, 'email_verified' => true, 'name' => 'Learner'])),
        ]))]);
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(fn () => (new GoogleProvider(request(), 'client', 'secret', 'http://localhost/auth/google/callback'))->setHttpClient($http));
    }

    private function linked(): User
    {
        $u = User::factory()->create(['email' => 'google@example.com', 'remember_token' => null]);
        $u->forceFill(['google_id' => 'remember-subject'])->save();

        return $u;
    }

    private function finishOAuth(string $extra = '')
    {
        return $this->get('/auth/google/callback?state='.urlencode(session('state')).'&code=fake'.$extra);
    }

    public function test_true_flow_queues_secure_recaller_and_restores_login_without_session_then_logout_invalidates_it(): void
    {
        $u = $this->linked();
        $this->provider();
        $oldSession = session()->getId();
        $this->post('/auth/google/redirect', ['remember' => true])->assertRedirect()->assertSessionHas('google_oauth.remember', true)->assertSessionHas('code_verifier');
        $name = Auth::guard()->getRecallerName();
        $response = $this->finishOAuth('&remember=0')->assertRedirect('/dashboard')->assertSessionMissing('google_oauth')->assertSessionMissing('state')->assertSessionMissing('code_verifier');
        $this->assertAuthenticatedAs($u);
        $this->assertNotSame($oldSession, session()->getId());
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $token = $u->fresh()->remember_token;
        $this->assertNotEmpty($token);
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie->getValue())->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($u);
        $this->assertTrue(Auth::viaRemember());
        $this->post('/logout')->assertRedirect('/login')->assertCookieExpired($name);
        $this->assertNotSame($token, $u->fresh()->remember_token);
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie->getValue())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_false_and_legacy_get_ignore_query_remember_and_issue_no_persistent_cookie(): void
    {
        $u = $this->linked();
        $this->provider();
        $this->post('/auth/google/redirect', ['remember' => false])->assertSessionHas('google_oauth.remember', false);
        $this->finishOAuth('&remember=1')->assertCookieExpired(Auth::guard()->getRecallerName());
        $this->assertNull($u->fresh()->remember_token);
    }

    public function test_legacy_get_overwrites_old_true_preference_and_ignores_query(): void
    {
        $this->provider();
        $this->withSession(['google_oauth' => ['remember' => true]])->get('/auth/google/redirect?remember=1')->assertRedirect()->assertSessionHas('google_oauth.remember', false);
    }

    public function test_inertia_post_returns_external_location_and_invalid_input_clears_old_flow(): void
    {
        $this->provider();
        $this->withHeader('X-Inertia', 'true')->post('/auth/google/redirect', ['remember' => true])->assertStatus(409)->assertHeader('X-Inertia-Location')->assertSessionHas('google_oauth.remember', true);
        $this->postJson('/auth/google/redirect', ['remember' => ['bad']])->assertUnprocessable()->assertSessionMissing('google_oauth')->assertSessionMissing('state');
    }

    public function test_new_user_remember_is_carried_through_registration(): void
    {
        $this->provider();
        $this->post('/auth/google/redirect', ['remember' => true]);
        $this->finishOAuth()->assertRedirect('/account/disclosure')->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotEmpty(User::first()->remember_token);
    }

    public function test_password_link_keeps_trusted_preference_on_typo_but_never_accepts_post_override(): void
    {
        $u = User::factory()->create(['email' => 'google@example.com']);
        $this->provider();
        $this->post('/auth/google/redirect', ['remember' => true]);
        $this->finishOAuth()->assertRedirect('/auth/google/link')->assertSessionHas('google_link.remember', true)->assertSessionMissing('google_oauth');
        $this->post('/auth/google/link', ['password' => 'wrong', 'remember' => false])->assertSessionHasErrors('password')->assertSessionHas('google_link.remember', true);
        $this->assertGuest();
        $this->post('/auth/google/link', ['password' => 'password', 'remember' => false])->assertRedirect('/dashboard')->assertCookie(Auth::guard()->getRecallerName())->assertSessionMissing('google_link');
        $this->assertAuthenticatedAs($u);
    }

    public function test_missing_flow_never_uses_callback_preference_even_with_valid_provider_exchange(): void
    {
        $u = $this->linked();
        $this->provider();
        $this->withSession(['state' => 'legacy-state', 'code_verifier' => str_repeat('v', 64)])->get('/auth/google/callback?state=legacy-state&code=fake&remember=1')->assertRedirect('/dashboard')->assertCookieExpired(Auth::guard()->getRecallerName());
        $this->assertNull($u->fresh()->remember_token);
    }

    public function test_expired_state_mismatched_cancelled_and_failed_flows_clear_preference(): void
    {
        $this->linked();
        Socialite::shouldReceive('driver')->never();
        foreach (['expired', 'wrong', 'cancel'] as $kind) {
            $flow = ['remember' => true, 'state' => 'expected', 'expires_at' => $kind === 'expired' ? time() - 1 : time() + 600];
            $url = '/auth/google/callback?state='.($kind === 'wrong' ? 'other' : 'expected').'&code=fake'.($kind === 'cancel' ? '&error=access_denied' : '');
            $this->withSession(['google_oauth' => $flow, 'state' => 'expected', 'code_verifier' => 'private'])->get($url)->assertRedirect('/login')->assertSessionMissing('google_oauth')->assertSessionMissing('state')->assertSessionMissing('code_verifier')->assertCookieMissing(Auth::guard()->getRecallerName());
            $this->assertGuest();
        }
    }

    public function test_link_cancel_and_expiry_remove_remember_preference(): void
    {
        $this->withSession(['google_link' => ['remember' => true, 'expires_at' => time() + 600], 'google_oauth' => ['remember' => true]])->post('/auth/google/link/cancel')->assertSessionMissing('google_link')->assertSessionMissing('google_oauth');
        $this->withSession(['google_link' => ['remember' => true, 'expires_at' => time() - 1]])->get('/auth/google/link')->assertRedirect('/login')->assertSessionMissing('google_link');
    }

    public function test_suspended_account_never_gets_authenticated_or_persistent_cookie(): void
    {
        $u = $this->linked();
        $u->forceFill(['suspended_at' => now()])->save();
        $this->provider();
        $this->post('/auth/google/redirect', ['remember' => true]);
        $this->finishOAuth()->assertRedirect('/login')->assertSessionHasErrors('google')->assertSessionMissing('google_oauth')->assertCookieExpired(Auth::guard()->getRecallerName());
        $this->assertGuest();
        $this->assertNull($u->fresh()->remember_token);
    }

    public function test_false_choice_expires_any_stale_browser_recaller(): void
    {
        $this->linked();
        $this->provider();
        $name = Auth::guard()->getRecallerName();
        $this->withCookie($name, '999|stale-token|stale-hash')->post('/auth/google/redirect', ['remember' => false]);
        $this->finishOAuth()->assertRedirect('/dashboard')->assertCookieExpired($name);
    }

    public function test_post_initiation_requires_csrf_outside_test_bypass(): void
    {
        Socialite::shouldReceive('driver')->never();
        $environment = $this->app['env'];
        try {
            $this->app['env'] = 'local';
            $this->postJson('/auth/google/redirect', ['remember' => true])->assertStatus(419)->assertSessionMissing('google_oauth');
        } finally {
            $this->app['env'] = $environment;
        }
    }
}
