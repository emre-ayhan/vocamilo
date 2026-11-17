<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_profile_or_change_password(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->put('/profile/password')->assertRedirect('/login');
    }

    public function test_profile_displays_account_without_password_or_provider_id(): void
    {
        $user = User::factory()->create(['google_id' => 'provider-secret']);
        $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->component('Account/Profile')->where('googleLinked', true)
            ->where('auth.user.email', $user->email)->missing('auth.user.password')->missing('auth.user.google_id'));
    }

    public function test_password_change_requires_correct_current_password_and_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user)->from('/profile')->put('/profile/password', [
            'current_password' => 'wrong-password', 'password' => 'new-password-long', 'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors(['current_password', 'password']);
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_password_change_enforces_minimum_length(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'original-password', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_password_change_hashes_password_and_rotates_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'original-password', 'remember_token' => 'old-token']);
        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'original-password', 'password' => 'new-password-long', 'password_confirmation' => 'new-password-long',
        ])->assertRedirect('/profile')->assertSessionHas('success', 'Password updated.');
        $this->assertTrue(Hash::check('new-password-long', $user->fresh()->password));
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
    }
}
