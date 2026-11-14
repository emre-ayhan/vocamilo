<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedAccessAuditTest extends TestCase
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
        $collection = $owner->collections()->create(['title' => 'Original title']);
        $collection->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $word = $owner->words()->create(['term' => 'word', 'definition' => 'meaning', 'note' => 'OWNER_PRIVATE_NOTE']);
        $collection->words()->attach($word);

        return [$owner, $collection, $word];
    }

    public function test_all_guest_modes_have_no_private_notes_history_or_persistence(): void
    {
        [$owner, $collection, $word] = $this->fixture();
        foreach (['flashcards', 'study', 'test'] as $mode) {
            $this->get('/s/'.$collection->share_uuid.'/study?mode='.$mode)
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('shared', true)->where('auth.user', null)
                ->where('collection.id', null)->where('mode', $mode)
                ->missing('words.0.note')->missing('collection.words.0.note')
                ->missing('summary')->missing('results'))
                ->assertDontSee('OWNER_PRIVATE_NOTE')->assertDontSee($owner->email);
            $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => $mode])->assertUnauthorized();
        }
        $this->getJson('/collections/'.$collection->id.'/words/'.$word->id.'/note')->assertUnauthorized();
        $this->putJson('/collections/'.$collection->id.'/words/'.$word->id.'/note', ['note' => 'unauthorized'])->assertUnauthorized();
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertSame('OWNER_PRIVATE_NOTE', $word->fresh()->note);
    }

    public function test_signed_in_bearer_without_membership_still_has_only_public_access(): void
    {
        [, $collection, $word] = $this->fixture();
        $this->actingAs(User::factory()->create());
        $this->get('/s/'.$collection->share_uuid.'/study?mode=study')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shared', true)->missing('words.0.note')->missing('summary'));
        $this->get('/collections/'.$collection->id)->assertNotFound();
        $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'study'])->assertNotFound();
        $this->getJson('/collections/'.$collection->id.'/words/'.$word->id.'/note')->assertNotFound();
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_member_session_settings_do_not_change_owner_session_or_collection(): void
    {
        [$owner, $collection] = $this->fixture();
        $member = User::factory()->create();
        $collection->members()->attach($member, ['progress_consented_at' => now()]);
        $settings = ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1];
        $ownerSession = $this->actingAs($owner)->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'study', 'settings' => $settings])->assertCreated()->json();
        $memberSettings = ['type' => 'choice', 'direction' => 'definition', 'order' => 'random', 'count' => 1];
        $memberSession = $this->actingAs($member)->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'test', 'settings' => $memberSettings])->assertCreated()->json();
        $this->assertSame($settings, StudySession::findOrFail($ownerSession['id'])->settings);
        $this->assertSame($memberSettings, StudySession::findOrFail($memberSession['id'])->settings);
        $this->assertSame('Original title', $collection->fresh()->title);
        $this->postJson('/collections/'.$collection->id.'/sessions/'.$ownerSession['id'].'/complete', ['answers' => []])->assertNotFound();
        $this->assertNull(StudySession::findOrFail($ownerSession['id'])->completed_at);
    }
}
