<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_locale_is_saved_in_cookie_and_returned_on_next_visit(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'pt'])->assertRedirect('/login')->assertCookie('locale', 'pt');
        $this->withCookie('locale', 'pt')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'pt'));
    }

    public function test_account_locale_wins_over_other_devices_cookie(): void
    {
        $user = User::factory()->create(['locale' => 'pt']);
        $this->actingAs($user)->withCookie('locale', 'en')->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'pt'));
        $this->post('/locale', ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->withCookie('locale', 'pt')->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_invalid_locales_do_not_change_saved_preference(): void
    {
        $user = User::factory()->create(['locale' => 'pt']);
        $this->actingAs($user)->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->assertSame('pt', $user->fresh()->locale);
    }

    public function test_portuguese_validation_and_registration_inherit_guest_preference(): void
    {
        $this->withCookie('locale', 'pt')->post('/register', [])->assertSessionHasErrors(['name' => 'O campo nome é obrigatório.']);
        $this->withCookie('locale', 'pt')->post('/register', ['name' => 'Maria', 'email' => 'maria@example.test', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'maria@example.test', 'locale' => 'pt']);
    }

    public function test_guest_invalid_cookie_falls_back_to_english(): void
    {
        $this->withCookie('locale', 'fr')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_csv_errors_translate_dynamic_rows_and_expired_preview(): void
    {
        $user = User::factory()->create(['locale' => 'pt']);
        $file = UploadedFile::fake()->createWithContent('words.csv', "term,definition\n,missing term\n");
        $pool = $user->wordPools()->create(['name' => 'English', 'language' => 'en']);
        $response = $this->actingAs($user)->postJson('/imports/preview', ['pool_id' => $pool->id, 'file' => $file, 'has_header' => true]);
        $response->assertUnprocessable()->assertJsonPath('errors.file.0', 'Palavra ou definição inválida na linha 2 do CSV. Utilize UTF-8 e preencha as colunas de palavra e definição.');
        $this->postJson('/imports/store')->assertUnprocessable()->assertJsonPath('message', 'A pré-visualização expirou. Carregue novamente o CSV.');
    }
    public function test_turkish_guest_preference_and_registration(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'tr'])->assertRedirect('/login')->assertCookie('locale', 'tr');
        $this->withCookie('locale', 'tr')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
        $this->withCookie('locale', 'tr')->post('/register', [])->assertSessionHasErrors(['name' => 'Ad alanı zorunludur.']);
        $this->withCookie('locale', 'tr')->post('/register', [
            'name' => 'Ayşe', 'email' => 'ayse@example.test', 'password' => 'secure-password', 'password_confirmation' => 'secure-password',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'ayse@example.test', 'locale' => 'tr']);
    }

    public function test_profile_language_selection_persists_turkish_and_translates_validation_and_success(): void
    {
        $user = User::factory()->create(['locale' => 'en', 'password' => 'original-password']);
        $this->actingAs($user)->from('/profile')->post('/locale', ['locale' => 'tr'])->assertRedirect('/profile')->assertCookie('locale', 'tr');
        $this->assertSame('tr', $user->fresh()->locale);
        $this->withCookie('locale', 'en')->get('/profile')->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
        $this->put('/profile/password', [
            'current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors(['current_password' => 'Mevcut şifre yanlış.', 'password' => 'Şifre en az 10 karakter olmalıdır.']);
        $this->put('/profile/password', [
            'current_password' => 'original-password', 'password' => 'new-password-long', 'password_confirmation' => 'new-password-long',
        ])->assertRedirect('/profile');
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Şifre güncellendi.'));
    }

    public function test_turkish_server_dictionary_preserves_placeholders_and_covers_portuguese_keys(): void
    {
        $pt = json_decode(file_get_contents(lang_path('pt.json')), true, flags: JSON_THROW_ON_ERROR);
        $tr = json_decode(file_get_contents(lang_path('tr.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($pt as $key => $value) {
            $this->assertNotEmpty($tr[$key] ?? null, $key);
            preg_match_all('/\{[^}]+\}|:[a-z_]+/', $key, $source);
            preg_match_all('/\{[^}]+\}|:[a-z_]+/', $tr[$key], $translated);
            sort($source[0]);
            sort($translated[0]);
            $this->assertSame($source[0], $translated[0], $key);
        }
    }

}
