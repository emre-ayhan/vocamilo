<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class CollectionMembershipTest extends TestCase
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
        $collection = $owner->collections()->create(['title' => 'Original collection']);
        $collection->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $word = $owner->words()->create(['term' => 'Bright', 'definition' => 'Full of light', 'note' => 'OWNER SECRET']);
        $collection->words()->attach($word);

        return [$owner, $member, $collection, $word];
    }

    public function test_guest_join_survives_registration_and_creates_no_copy(): void
    {
        [$owner, , $c] = $this->fixture();
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login')->assertSessionHas('pending_collection_uuid', $c->share_uuid);
        $this->post('/register', ['name' => 'New member', 'email' => 'new@example.com', 'password' => 'strong-password-123', 'password_confirmation' => 'strong-password-123'])->assertRedirect('/account/disclosure');
        $this->post('/account/disclosure', ['accepted' => true])->assertRedirect('/collections/'.$c->id)->assertSessionMissing('pending_collection_uuid');
        $member = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($c->members()->whereKey($member->id)->exists());
        $this->assertDatabaseCount('collections', 1);
        $this->assertDatabaseCount('words', 1);
        $this->assertSame(0, $member->words()->count());
    }

    public function test_email_login_preserves_join_and_repeated_join_is_idempotent(): void
    {
        [, $member, $c] = $this->fixture();
        $member->update(['password' => 'strong-password-123']);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $this->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertFalse($c->members()->whereKey($member->id)->exists());
        $this->post('/login', ['email' => $member->email, 'password' => 'strong-password-123'])->assertRedirect('/collections/'.$c->id);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/collections/'.$c->id);
        $this->assertSame(1, $c->members()->count());
    }

    public function test_google_registration_resumes_only_validated_internal_join(): void
    {
        [, , $c] = $this->fixture();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $google = (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'join-subject', 'email' => 'new@gmail.com', 'name' => 'New member']);
        $driver = Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2, 'next' => 'https://evil.example'])->assertRedirect('/login');
        $this->get('/auth/google/callback?next=https://evil.example')->assertRedirect('/account/disclosure');
        $this->post('/account/disclosure', ['accepted' => true])->assertRedirect('/collections/'.$c->id);
        $this->assertTrue($c->members()->where('email', 'new@gmail.com')->exists());
    }

    public function test_revoked_pending_join_and_malformed_uuid_never_create_membership(): void
    {
        [, $member, $c] = $this->fixture();
        $this->post('/s/not-a-uuid/join')->assertNotFound();
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $c->forceFill(['share_uuid' => null])->save();
        $member->update(['password' => 'strong-password-123']);
        $this->post('/login', ['email' => $member->email, 'password' => 'strong-password-123'])->assertRedirect('/dashboard')->assertSessionMissing('pending_collection_uuid');
        $this->assertSame(0, $c->members()->count());
    }

    public function test_member_sees_original_changes_but_cannot_modify_owner_content(): void
    {
        [$owner, $member, $c, $word] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $word->update(['definition' => 'Updated original']);
        $this->actingAs($member)->get('/collections/'.$c->id)->assertOk()->assertDontSee('OWNER SECRET');
        $this->get('/collections/'.$c->id.'/study')->assertOk()->assertInertia(fn (Assert $page) => $page->where('words.0.definition', 'Updated original'))->assertDontSee('OWNER SECRET');
        $this->put('/collections/'.$c->id, ['title' => 'stolen'])->assertNotFound();
        $this->delete('/collections/'.$c->id)->assertNotFound();
        $this->post('/collections/'.$c->id.'/words', ['word_ids' => [$word->id]])->assertNotFound();
        $this->delete('/collections/'.$c->id.'/words/'.$word->id)->assertNotFound();
        $this->post('/collections/'.$c->id.'/sharing')->assertNotFound();
        $this->delete('/collections/'.$c->id.'/sharing')->assertNotFound();
        $this->put('/words/'.$word->id, ['term' => 'stolen', 'definition' => 'x'])->assertNotFound();
        $this->assertSame('Original collection', $c->fresh()->title);
    }

    public function test_unshare_permanently_revokes_membership_even_after_new_link(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $old = $c->share_uuid;
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->actingAs($owner)->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->assertSame(0, $c->members()->count());
        $this->actingAs($member)->get('/collections/'.$c->id)->assertNotFound();
        $this->get('/collections/'.$c->id.'/study')->assertNotFound();
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study'])->assertNotFound();
        $this->actingAs($owner)->post('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->assertNotSame($old, $c->fresh()->share_uuid);
        $this->actingAs($member)->get('/collections/'.$c->id)->assertNotFound();
        $this->post('/s/'.$old.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertNotFound();
        $this->post('/s/'.$c->fresh()->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/collections/'.$c->id);
        $this->get('/collections/'.$c->id)->assertOk();
    }

    public function test_member_notes_and_results_are_private_and_owner_content_stays_unchanged(): void
    {
        [$owner, $member, $c, $word] = $this->fixture();
        $other = User::factory()->create();
        $c->members()->attach([$member->id, $other->id], ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->actingAs($member)->put('/collections/'.$c->id.'/words/'.$word->id.'/note', ['note' => 'MEMBER SECRET'])->assertRedirect();
        $this->get('/collections/'.$c->id.'/study')->assertInertia(fn (Assert $p) => $p->where('words.0.note', 'MEMBER SECRET'))->assertDontSee('OWNER SECRET');
        $this->assertSame('OWNER SECRET', $word->fresh()->note);
        $start = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study', 'settings' => ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
        $this->postJson('/collections/'.$c->id.'/sessions/'.$start['id'].'/complete', ['answers' => [['word_id' => $word->id, 'answer' => 'Bright', 'attempt_no' => 1]]])->assertOk();
        $this->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('summary.total_sessions', 1));
        foreach ([$owner, $other] as $viewer) {
            $this->actingAs($viewer)->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('summary.total_sessions', 0))->assertDontSee('MEMBER SECRET');
            $this->postJson('/collections/'.$c->id.'/sessions/'.$start['id'].'/complete', ['answers' => []])->assertNotFound();
        }
        $this->get('/s/'.$c->share_uuid)->assertDontSee('MEMBER SECRET')->assertDontSee('OWNER SECRET');
        $foreignWord = $owner->words()->create(['term' => 'Detached', 'definition' => 'not in collection']);
        $this->actingAs($member)->put('/collections/'.$c->id.'/words/'.$foreignWord->id.'/note', ['note' => 'x'])->assertNotFound();
    }

    public function test_newest_fifteen_cursor_pages_cover_all_words_and_study_is_not_paginated(): void
    {
        [$owner, $member, $c, $word] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $ids = [$word->id];
        for ($i = 1; $i <= 31; $i++) {
            $w = $owner->words()->create(['term' => 'Word '.$i, 'definition' => 'Definition '.$i]);
            $ids[] = $w->id;
            $c->words()->attach($w, ['added_at' => now()->addSeconds($i)]);
        }
        $this->actingAs($member)->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->has('collection.words', 15)->where('collection.words.0.id', end($ids))->where('collection.words_count', 32));
        $all = [];
        $cursor = null;
        do {
            $page = $this->getJson('/collections/'.$c->id.'/words'.($cursor ? '?cursor='.urlencode($cursor) : ''))->assertOk()->json();
            $this->assertLessThanOrEqual(15, count($page['data']));
            $all = array_merge($all, array_column($page['data'], 'id'));
            $cursor = $page['next_cursor'];
        } while ($cursor);
        $this->assertSame(array_reverse($ids), $all);
        $this->get('/collections/'.$c->id.'/study')->assertInertia(fn (Assert $p) => $p->has('words', 32));
        $this->get('/s/'.$c->share_uuid.'/study')->assertInertia(fn (Assert $p) => $p->has('words', 32));
        $this->getJson('/s/'.$c->share_uuid.'/words')->assertJsonCount(15, 'data')->assertDontSee('OWNER SECRET');
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson('/collections/'.$c->id.'/words')->assertNotFound();
    }

    public function test_csv_exports_are_scoped_and_formula_safe_in_all_columns(): void
    {
        [$owner, $member, $c, $word] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $word->update(['term' => '=HYPERLINK("https://evil.example")', 'definition' => " \t+SUM(1,2)", 'note' => '@OWNER']);
        $this->actingAs($member)->put('/collections/'.$c->id.'/words/'.$word->id.'/note', ['note' => '-MEMBER'])->assertRedirect();
        $memberCsv = $this->get('/collections/'.$c->id.'/export')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
        $rows = $this->csvRows($memberCsv);
        $this->assertSame(['term', 'definition', 'note'], $rows[0]);
        $this->assertSame(["'".$word->term, "'".$word->definition, "'-MEMBER"], $rows[1]);
        $this->assertStringNotContainsString('OWNER', $memberCsv);
        $this->assertCount(1, $this->csvRows($this->get('/words/export')->assertOk()->streamedContent()));
        $ownerCsv = $this->actingAs($owner)->get('/words/export')->assertOk()->streamedContent();
        $this->assertSame("'@OWNER", $this->csvRows($ownerCsv)[1][2]);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/collections/'.$c->id.'/export')->assertNotFound();
        $this->actingAs($owner)->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->actingAs($member)->get('/collections/'.$c->id.'/export')->assertNotFound();
        $this->post('/logout');
        $this->get('/words/export')->assertRedirect('/login');
        $this->get('/collections/'.$c->id.'/export')->assertRedirect('/login');
    }

    public function test_google_password_link_preserves_join_until_password_proof(): void
    {
        [, $member, $c] = $this->fixture();
        $member->update(['password' => 'strong-password-123']);
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => 'http://localhost/auth/google/callback']);
        $google = (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'existing-account-subject', 'email' => $member->email, 'name' => 'Member']);
        $driver = Mockery::mock();
        $driver->shouldReceive('enablePKCE')->once()->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($google);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
        $this->post('/s/'.$c->share_uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect('/login');
        $this->get('/auth/google/callback')->assertRedirect('/auth/google/link');
        $this->assertSame(0, $c->members()->count());
        $this->post('/auth/google/link', ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->assertSame(0, $c->members()->count());
        $this->post('/auth/google/link', ['password' => 'strong-password-123'])->assertRedirect('/collections/'.$c->id)->assertSessionMissing('pending_collection_uuid');
        $this->assertTrue($c->members()->whereKey($member->id)->exists());
    }

    public function test_malformed_pending_intent_cannot_become_external_redirect(): void
    {
        $member = User::factory()->create(['password' => 'strong-password-123']);
        $this->withSession(['pending_collection_uuid' => 'https://evil.example'])->post('/login', ['email' => $member->email, 'password' => 'strong-password-123'])->assertRedirect('/dashboard')->assertSessionMissing('pending_collection_uuid');
        $this->assertDatabaseCount('collection_members', 0);
    }

    public function test_revocation_prevents_completion_of_inflight_member_session(): void
    {
        [$owner, $member, $c, $word] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $session = $this->actingAs($member)->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'test', 'settings' => ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
        $this->actingAs($owner)->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->actingAs($member)->postJson('/collections/'.$c->id.'/sessions/'.$session['id'].'/complete', ['answers' => [['word_id' => $word->id, 'answer' => 'Bright']]])->assertNotFound();
        $this->assertDatabaseCount('study_answers', 0);
    }

    public function test_dashboard_lists_active_membership_and_owner_flag_without_copying_pool(): void
    {
        [$owner, $member, $c] = $this->fixture();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->actingAs($member)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.collections', 1)->where('stats.joined', 1)->where('stats.words', 0)->where('collections.0.isOwner', false)->where('collections.0.id', $c->id));
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('collections.0.isOwner', true));
        $this->delete('/collections/'.$c->id.'/sharing');
        $this->actingAs($member)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.collections', 0)->where('stats.joined', 0)->has('collections', 0));
    }

    public function test_collection_available_words_excludes_attached_words_beyond_first_page(): void
    {
        [$owner, , $c] = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $word = $owner->words()->create(['term' => 'Attached '.$i, 'definition' => 'Meaning']);
            $c->words()->attach($word);
        }
        $available = $owner->words()->create(['term' => 'Available', 'definition' => 'Not attached']);
        $this->actingAs($owner)->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->has('collection.words', 15)->has('availableWords', 1)->where('availableWords.0.id', $available->id));
    }

    public function test_csv_preserves_multiline_unicode_and_neutralizes_control_prefixed_cells(): void
    {
        $owner = User::factory()->create();
        $values = ["\tplain", "\rplain", "\nplain", "\u{FEFF}=SUM(1,2)", "\u{200B}@SUM(1,2)", " \t=1+1"];
        foreach ($values as $i => $value) {
            $owner->words()->create(['term' => $value, 'definition' => "Português, \"quoted\"\nnext line", 'note' => 'safe']);
        }
        $rows = $this->csvRows($this->actingAs($owner)->get('/words/export')->assertOk()->streamedContent());
        foreach ($values as $i => $value) {
            $this->assertSame("'".$value, $rows[$i + 1][0]);
            $this->assertSame("Português, \"quoted\"\nnext line", $rows[$i + 1][1]);
            $this->assertSame('safe', $rows[$i + 1][2]);
        }
    }

    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
