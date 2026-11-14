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
        $response = $this->actingAs($user)->postJson('/imports/preview', ['file' => $file, 'has_header' => true]);
        $response->assertUnprocessable()->assertJsonPath('errors.file.0', 'Palavra ou definição inválida na linha 2 do CSV. Utilize UTF-8 e preencha as colunas de palavra e definição.');
        $this->postJson('/imports/store')->assertUnprocessable()->assertJsonPath('message', 'A pré-visualização expirou. Carregue novamente o CSV.');
    }
}
