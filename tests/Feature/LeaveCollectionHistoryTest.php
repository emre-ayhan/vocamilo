<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeaveCollectionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_hides_progress_until_fresh_rejoin_consent_without_erasing_notes_history_or_link(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $collection = $owner->collections()->create(['title' => 'Shared language']);
        $collection->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $uuid = $collection->share_uuid;
        $word = $owner->words()->create(['term' => 'Light', 'definition' => 'Not heavy', 'note' => 'OWNER PRIVATE']);
        $collection->words()->attach($word);
        foreach ([$member, $other] as $user) {
            $collection->members()->attach($user, ['progress_consent_version' => 2, 'progress_consented_at' => now()]);
        }
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $word->id, 'note' => 'MEMBER PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $member->id, 'collection_id' => $collection->id, 'mode' => 'study', 'settings' => [], 'questions' => [], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => now()]);
        $base = '/collections/'.$collection->id;
        $this->actingAs($member)->delete($base.'/membership')->assertRedirect('/collections');
        $this->assertDatabaseMissing('collection_members', ['collection_id' => $collection->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('collection_members', ['collection_id' => $collection->id, 'user_id' => $other->id]);
        $this->assertDatabaseHas('study_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('user_word_notes', ['user_id' => $member->id, 'note' => 'MEMBER PRIVATE']);
        $this->assertSame($uuid, $collection->fresh()->share_uuid);
        $this->get($base)->assertNotFound();
        $this->get($base.'/export')->assertNotFound();
        $this->actingAs($owner)->get($base.'/sharing/members?member='.$member->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember', null));
        $this->actingAs($member)->postJson('/s/'.$uuid.'/join', ['progress_consent' => true])->assertUnprocessable();
        $this->post('/s/'.$uuid.'/join', ['progress_consent' => true, 'consent_version' => 2])->assertRedirect($base);
        $this->get($base)->assertInertia(fn (Assert $p) => $p->where('summary.total_sessions', 1));
        $this->getJson($base.'/words/'.$word->id.'/note')->assertJsonPath('note', 'MEMBER PRIVATE');
        $this->actingAs($owner)->get($base.'/sharing/members?member='.$member->id)->assertInertia(fn (Assert $p) => $p->where('selectedMember.summary.total_sessions', 1))->assertDontSee('MEMBER PRIVATE')->assertDontSee('OWNER PRIVATE');
    }
}
