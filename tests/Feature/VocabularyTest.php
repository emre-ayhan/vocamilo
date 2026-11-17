<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function word(User $user, string $term = 'bright')
    {
        $word = $user->words()->create(['term' => $term, 'definition' => 'full of light', 'note' => 'PRIVATE_TRANSLATION']);
        $this->pool($user)->words()->attach($word);

        return $word;
    }

    private function pool(User $user)
    {
        return $user->wordPools()->firstOrCreate(['name' => 'Test pool'], ['language' => 'en']);
    }

    private function collection(User $user)
    {
        return $user->collections()->create(['title' => 'First words', 'description' => 'A small set']);
    }

    public function test_registration_hashes_password_and_authenticates_user(): void
    {
        $this->post('/register', ['name' => 'Learner', 'email' => 'LEARNER@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])->assertRedirect('/account/disclosure');
        $user = User::firstOrFail();
        $this->assertSame('learner@example.com', $user->email);
        $this->assertTrue(Hash::check('long-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_case_variant_of_existing_email(): void
    {
        User::factory()->create(['email' => 'learner@example.com']);
        $this->post('/register', ['name' => 'Learner', 'email' => 'LEARNER@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_registration_validates_password_confirmation(): void
    {
        $this->post('/register', ['name' => 'Learner', 'email' => 'learner@example.com', 'password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_logout_and_failed_login(): void
    {
        $user = User::factory()->create(['email' => 'learner@example.com']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'LEARNER@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_private_routes_require_authentication(): void
    {
        foreach (['/dashboard', '/words', '/imports', '/collections', '/collections/1', '/collections/1/study'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/words', ['term' => 'x', 'definition' => 'y'])->assertRedirect('/login');
    }

    public function test_word_crud_is_owner_scoped_and_validated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->word($other, 'foreign');
        $this->actingAs($owner)->post('/words', ['pool_id' => $this->pool($owner)->id, 'term' => 'sun', 'definition' => 'a star', 'note' => 'my note', 'user_id' => $other->id])->assertRedirect();
        $mine = $owner->words()->firstOrFail();
        $this->assertDatabaseHas('words', ['id' => $mine->id, 'user_id' => $owner->id]);
        $this->put('/words/'.$mine->id, ['term' => 'moon', 'definition' => 'a satellite', 'note' => null])->assertRedirect();
        $this->assertSame('moon', $mine->fresh()->term);
        $this->put('/words/'.$foreign->id, ['term' => 'stolen', 'definition' => 'no'])->assertNotFound();
        $this->delete('/words/'.$foreign->id)->assertNotFound();
        $this->post('/words', ['term' => '', 'definition' => ''])->assertSessionHasErrors(['term', 'definition']);
        $this->delete('/words/'.$mine->id)->assertRedirect();
        $this->assertDatabaseMissing('words', ['id' => $mine->id]);
        $this->assertDatabaseHas('words', ['id' => $foreign->id, 'term' => 'foreign']);
    }

    public function test_lists_search_and_dashboard_do_not_leak_other_users_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->word($owner, 'bright');
        $this->word($other, 'bright foreign');
        $this->collection($owner);
        $this->collection($other);
        $this->actingAs($owner)->get('/word-pools/'.$this->pool($owner)->id.'?search=bright')->assertInertia(fn (Assert $p) => $p->component('Words/Index')->has('words.data', 1)->where('words.data.0.term', 'bright'));
        $this->get('/collections')->assertInertia(fn (Assert $p) => $p->component('Collections/Index')->has('collections', 1));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('Dashboard')->where('stats.words', 1)->where('stats.collections', 1));
    }

    public function test_all_collection_actions_reject_another_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $c = $this->collection($other);
        $w = $this->word($other);
        $c->words()->attach($w);
        $this->actingAs($owner);
        $base = '/collections/'.$c->id;
        $this->get($base)->assertNotFound();
        $this->get($base.'/study')->assertNotFound();
        $this->put($base, ['title' => 'Changed'])->assertNotFound();
        $this->delete($base)->assertNotFound();
        $this->post($base.'/words', ['word_ids' => [$w->id]])->assertNotFound();
        $this->delete($base.'/words/'.$w->id)->assertNotFound();
        $this->post($base.'/sharing')->assertNotFound();
        $this->delete($base.'/sharing')->assertNotFound();
        $this->assertNull($c->fresh()->share_uuid);
        $this->assertSame('First words', $c->fresh()->title);
        $this->assertSame(1, $c->words()->count());
    }

    public function test_collection_crud_and_membership_preserve_word_pool(): void
    {
        $owner = User::factory()->create();
        $word = $this->word($owner);
        $this->actingAs($owner)->post('/collections', ['title' => 'Spelling'])->assertRedirect();
        $c = $owner->collections()->firstOrFail();
        $this->put('/collections/'.$c->id, ['title' => 'Spelling two', 'description' => 'Practice'])->assertRedirect();
        $this->post('/collections/'.$c->id.'/words', ['word_ids' => [$word->id]])->assertRedirect();
        $this->post('/collections/'.$c->id.'/words', ['word_ids' => [$word->id]])->assertRedirect();
        $this->assertSame(1, $c->words()->count());
        $this->delete('/collections/'.$c->id.'/words/'.$word->id)->assertRedirect();
        $this->assertSame(0, $c->words()->count());
        $this->delete('/collections/'.$c->id)->assertRedirect('/collections');
        $this->assertDatabaseHas('words', ['id' => $word->id]);
    }

    public function test_attaching_foreign_words_is_rejected_without_partial_changes(): void
    {
        $owner = User::factory()->create();
        $c = $this->collection($owner);
        $mine = $this->word($owner);
        $foreign = $this->word(User::factory()->create());
        $this->actingAs($owner)->post('/collections/'.$c->id.'/words', ['word_ids' => [$mine->id, $foreign->id]])->assertUnprocessable();
        $this->assertSame(0, $c->words()->count());
    }

    public function test_share_uuid_is_revocable_and_rotates_on_reenable(): void
    {
        $owner = User::factory()->create();
        $c = $this->collection($owner);
        $this->actingAs($owner)->post('/collections/'.$c->id.'/sharing')->assertRedirect();
        $first = $c->fresh()->share_uuid;
        $this->assertTrue(Str::isUuid($first));
        $this->post('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->assertSame($first, $c->fresh()->share_uuid);
        $this->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->get('/s/'.$first)->assertNotFound();
        $this->get('/s/'.$first.'/study')->assertNotFound();
        $this->post('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->assertNotSame($first, $c->fresh()->share_uuid);
        $this->get('/s/'.$first)->assertNotFound();
    }

    public function test_guests_can_study_shared_words_without_private_notes_or_owner_identity(): void
    {
        $owner = User::factory()->create();
        $c = $this->collection($owner);
        $c->share_uuid = (string) Str::uuid();
        $c->save();
        $c->words()->attach($this->word($owner));
        foreach (['/s/'.$c->share_uuid, '/s/'.$c->share_uuid.'/study?mode=test'] as $url) {
            $response = $this->get($url);
            $response->assertOk()->assertInertia(fn (Assert $p) => $p->where('shared', true)->where('auth.user', null)->where('collection.id', null)->has('collection.words', 1)->missing('collection.words.0.note')->missing('collection.user_id')->missing('collection.email'));
            $response->assertDontSee('PRIVATE_TRANSLATION')->assertDontSee($owner->email);
        }
    }

    public function test_public_routes_reject_private_collection_ids_and_unknown_tokens(): void
    {
        $c = $this->collection(User::factory()->create());
        foreach ([(string) $c->id, 'not-a-uuid', (string) Str::uuid()] as $token) {
            $this->get('/s/'.$token)->assertNotFound();
            $this->get('/s/'.$token.'/study')->assertNotFound();
        }
    }

    public function test_study_modes_validate_and_owner_notes_are_excluded_from_test(): void
    {
        $owner = User::factory()->create();
        $c = $this->collection($owner);
        $c->words()->attach($this->word($owner));
        foreach (['flashcards', 'study', 'test'] as $mode) {
            $this->actingAs($owner)->get('/collections/'.$c->id.'/study?mode='.$mode)->assertInertia(fn (Assert $p) => $p->component('Study/Session')->where('mode', $mode)->when($mode === 'test', fn ($p) => $p->missing('words.0.note')->missing('collection.words.0.note'), fn ($p) => $p->where('words.0.note', 'PRIVATE_TRANSLATION')));
        }
        $this->get('/collections/'.$c->id.'/study?mode=invalid')->assertUnprocessable();
    }

    public function test_csv_preview_is_non_mutating_deduplicated_and_import_consumes_session(): void
    {
        $owner = User::factory()->create();
        $this->word($owner, 'bright');
        $c = $this->collection($owner);
        $csv = UploadedFile::fake()->createWithContent('words.csv', "term,definition\nbright,full of light\nsun,a star\nsun,a star\n");
        $this->actingAs($owner)->post('/imports/preview', ['pool_id' => $this->pool($owner)->id, 'file' => $csv, 'has_header' => true])->assertInertia(fn (Assert $p) => $p->component('Import/Index')->where('preview.total', 2)->where('preview.duplicates', 2));
        $this->assertDatabaseCount('words', 1);
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id'), 'collection_id' => $c->id])->assertRedirect('/collections/'.$c->id);
        $this->assertDatabaseCount('words', 2);
        $this->assertEqualsCanonicalizing(['bright', 'sun'], $c->words()->pluck('term')->all());
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertUnprocessable();
        $this->assertDatabaseCount('words', 2);
    }

    public function test_csv_mapping_and_no_header_are_supported(): void
    {
        $owner = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent('words.csv', "a star,sun\n");
        $this->actingAs($owner)->post('/imports/preview', ['pool_id' => $this->pool($owner)->id, 'file' => $csv, 'has_header' => false, 'term_column' => 1, 'definition_column' => 0])->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.term', 'sun')->where('preview.rows.0.definition', 'a star'));
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertRedirect('/word-pools/'.$this->pool($owner)->id);
        $this->assertDatabaseHas('words', ['user_id' => $owner->id, 'term' => 'sun', 'definition' => 'a star']);
    }

    public function test_csv_import_cannot_target_other_users_collection(): void
    {
        $owner = User::factory()->create();
        $foreign = $this->collection(User::factory()->create());
        $this->actingAs($owner)->post('/imports/preview', ['pool_id' => $this->pool($owner)->id, 'file' => UploadedFile::fake()->createWithContent('words.csv', "term,definition\nsun,a star\n"), 'has_header' => true])->assertOk();
        $this->post('/imports/store', ['preview_id' => session('csv_preview.preview_id'), 'collection_id' => $foreign->id])->assertNotFound();
        $this->assertDatabaseCount('words', 0);
    }

    public function test_csv_preview_session_is_expiring_and_bound_to_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $rows = [['term' => 'sun', 'definition' => 'a star']];
        $this->actingAs($owner)->withSession(['csv_preview' => ['version' => 3, 'user_id' => $owner->id, 'rows' => $rows, 'expires_at' => time() - 1]])->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertUnprocessable();
        $this->withSession(['csv_preview' => ['version' => 3, 'user_id' => $other->id, 'rows' => $rows, 'expires_at' => time() + 60]])->post('/imports/store', ['preview_id' => session('csv_preview.preview_id')])->assertUnprocessable();
        $this->assertDatabaseCount('words', 0);
    }

    public function test_csv_rejects_missing_oversized_or_invalid_content(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/imports/preview')->assertSessionHasErrors('file');
        $this->post('/imports/preview', ['pool_id' => $this->pool($owner)->id, 'file' => UploadedFile::fake()->create('large.csv', 2049)])->assertSessionHasErrors('file');
        $this->post('/imports/preview', ['pool_id' => $this->pool($owner)->id, 'file' => UploadedFile::fake()->createWithContent('bad.csv', "term,definition\nword,\n")])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('words', 0);
    }
}
