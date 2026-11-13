<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
    }

    private function providerUser(string $email = 'learner@gmail.com', string $sub = 'google-subject-1', array $raw = ['email_verified' => true]): void
    {
        $user = (new GoogleUser)->setRaw($raw)->map(['id' => $sub, 'email' => $email, 'name' => 'Google Learner']);
        $driver = Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($user);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
    }

    private function pending(User $user, array $overrides = []): array
    {
        return array_replace(['sub' => 'google-subject-1', 'email' => $user->email, 'name' => 'Google Learner', 'authoritative' => true, 'expires_at' => time() + 600, 'user_id' => $user->id], $overrides);
    }

    public function test_new_verified_gmail_user_is_created_and_signed_in(): void
    {
        $this->providerUser('LEARNER@gmail.com');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('learner@gmail.com', $user->email);
        $this->assertSame('google-subject-1', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotEmpty($user->password);
    }

    public function test_verified_workspace_user_is_authoritative(): void
    {
        $this->providerUser('learner@school.example', 'workspace-subject', ['email_verified' => true, 'hd' => 'school.example']);
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertNotNull(User::firstOrFail()->email_verified_at);
    }

    public function test_google_verified_third_party_email_does_not_become_locally_verified(): void
    {
        $this->providerUser('learner@example.com');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertNull(User::firstOrFail()->email_verified_at);
        $this->assertAuthenticated();
    }

    public function test_legacy_google_verified_email_claim_is_supported(): void
    {
        $this->providerUser('learner@gmail.com', 'google-subject-1', ['verified_email' => true]);
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_unverified_email_is_rejected_without_mutation(): void
    {
        $this->providerUser('learner@gmail.com', 'google-subject-1', ['email_verified' => false]);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_subject_is_rejected(): void
    {
        $this->providerUser('learner@gmail.com', '');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->providerUser('not-an-email');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_verification_claim_is_rejected(): void
    {
        $this->providerUser('learner@gmail.com', 'google-subject-1', []);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_returning_subject_signs_into_original_account_even_if_google_email_changed(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);
        $user->google_id = 'google-subject-1';
        $user->save();
        $this->providerUser('changed@gmail.com');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('original@example.com', $user->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_local_email_requires_password_before_linking(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'learner@gmail.com']);
        $this->providerUser();
        $this->get('/auth/google/callback')->assertRedirect('/auth/google/link')->assertSessionHas('google_link');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->get('/auth/google/link')->assertInertia(fn (Assert $p) => $p->component('Auth/LinkGoogle')->where('email', $user->email)->missing('sub')->missing('google_id'));
        $this->post('/auth/google/link', ['password' => 'password'])->assertRedirect('/dashboard')->assertSessionMissing('google_link');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-subject-1', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_wrong_link_password_changes_nothing(): void
    {
        $user = User::factory()->unverified()->create();
        $originalHash = $user->password;
        $this->withSession(['google_link' => $this->pending($user)])->post('/auth/google/link', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_non_authoritative_email_link_does_not_grant_email_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $this->withSession(['google_link' => $this->pending($user, ['authoritative' => false])])->post('/auth/google/link', ['password' => 'password'])->assertRedirect('/dashboard');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_pending_link_is_cleared_and_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $pending = $this->pending($user, ['expires_at' => time() - 1]);
        $this->withSession(['google_link' => $pending])->get('/auth/google/link')->assertRedirect('/login')->assertSessionMissing('google_link');
        $this->withSession(['google_link' => $pending])->post('/auth/google/link', ['password' => 'password'])->assertRedirect('/login')->assertSessionMissing('google_link');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_cancel_clears_pending_link(): void
    {
        $user = User::factory()->create();
        $this->withSession(['google_link' => $this->pending($user)])->post('/auth/google/link/cancel')->assertRedirect('/login')->assertSessionMissing('google_link');
        $this->post('/auth/google/link', ['password' => 'password'])->assertRedirect('/login');
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_existing_email_linked_to_different_google_subject_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'learner@gmail.com']);
        $user->google_id = 'another-subject';
        $user->save();
        $this->providerUser();
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google')->assertSessionMissing('google_link');
        $this->assertGuest();
        $this->assertSame('another-subject', $user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_pending_link_cannot_steal_subject_assigned_to_another_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->google_id = 'google-subject-1';
        $other->save();
        $this->withSession(['google_link' => $this->pending($user)])->post('/auth/google/link', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->google_id);
        $this->assertSame('google-subject-1', $other->fresh()->google_id);
        $this->assertGuest();
    }

    public function test_pending_link_rechecks_local_email_and_google_identity(): void
    {
        $user = User::factory()->create();
        $pending = $this->pending($user);
        $user->email = 'changed@example.com';
        $user->save();
        $this->withSession(['google_link' => $pending])->post('/auth/google/link', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->google_id);
        $user->google_id = 'newly-assigned-subject';
        $user->save();
        $this->withSession(['google_link' => $this->pending($user)])->post('/auth/google/link', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertSame('newly-assigned-subject', $user->fresh()->google_id);
    }

    public function test_disabled_configuration_disables_button_and_provider_endpoints(): void
    {
        config(['services.google.client_secret' => null]);
        Socialite::shouldReceive('driver')->never();
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('googleEnabled', false));
        $this->get('/register')->assertInertia(fn (Assert $p) => $p->where('googleEnabled', false));
        $this->get('/auth/google/redirect')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    }

    public function test_oauth_cancellation_forgets_state_and_pending_link(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->withSession(['state' => 'test-state', 'google_link' => ['expires_at' => time() + 600]])->get('/auth/google/callback?error=access_denied')->assertRedirect('/login')->assertSessionMissing('state')->assertSessionMissing('google_link')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_provider_exceptions_are_safe_errors_without_mutation(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_network_error_is_not_exposed_to_user(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andThrow(new \RuntimeException('SECRET_INTERNAL_DETAIL'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', session('errors')->first('google'));
        $this->assertGuest();
    }

    public function test_real_socialite_provider_rejects_mismatched_state_before_http(): void
    {
        $http = Mockery::mock(Client::class);
        $http->shouldNotReceive('post');
        $http->shouldNotReceive('get');
        Socialite::shouldReceive('driver')->once()->with('google')->andReturnUsing(function () use ($http) {
            return (new GoogleProvider(request(), 'test-client', 'test-secret', 'http://localhost/auth/google/callback'))->setHttpClient($http);
        });
        $this->withSession(['state' => 'expected-state'])->get('/auth/google/callback?state=incorrect-state&code=fake-code')->assertRedirect('/login')->assertSessionHasErrors('google')->assertSessionMissing('state');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_socialite_redirect_uses_state_and_clears_old_link(): void
    {
        Socialite::shouldReceive('driver')->once()->with('google')->andReturnUsing(fn () => new GoogleProvider(request(), 'test-client', 'test-secret', 'http://localhost/auth/google/callback'));
        $response = $this->withSession(['google_link' => ['expires_at' => time() + 600]])->get('/auth/google/redirect');
        $response->assertRedirect()->assertSessionMissing('google_link')->assertSessionHas('state');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertStringContainsString('openid', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty(session('code_verifier'));
    }

    public function test_google_id_is_hidden_from_serialization_and_inertia(): void
    {
        $user = User::factory()->create();
        $user->google_id = 'PRIVATE_GOOGLE_SUBJECT';
        $user->save();
        $this->assertArrayNotHasKey('google_id', $user->toArray());
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->missing('auth.user.google_id'))->assertDontSee('PRIVATE_GOOGLE_SUBJECT');
    }

    public function test_real_provider_exchanges_code_with_pkce_and_uses_server_userinfo(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'fake-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['sub' => 'server-subject', 'email' => 'server@gmail.com', 'email_verified' => true, 'name' => 'Server User'])),
        ]));
        $handler->push(Middleware::history($history));
        $http = new Client(['handler' => $handler]);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturnUsing(fn () => (new GoogleProvider(request(), 'test-client', 'test-secret', 'http://localhost/auth/google/callback'))->setHttpClient($http));
        $this->withSession(['state' => 'matching-state', 'code_verifier' => str_repeat('v', 64)])->get('/auth/google/callback?state=matching-state&code=fake-code&email=attacker@example.com')->assertRedirect('/dashboard')->assertSessionMissing('state')->assertSessionMissing('code_verifier');
        $this->assertCount(2, $history);
        parse_str((string) $history[0]['request']->getBody(), $body);
        $this->assertSame('fake-code', $body['code']);
        $this->assertSame(str_repeat('v', 64), $body['code_verifier']);
        $this->assertSame('Bearer fake-access-token', $history[1]['request']->getHeaderLine('Authorization'));
        $user = User::firstOrFail();
        $this->assertSame('server@gmail.com', $user->email);
        $this->assertSame('server-subject', $user->google_id);
        $this->assertAuthenticatedAs($user);
    }
}
