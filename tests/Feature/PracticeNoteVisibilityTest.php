<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PracticeNoteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_are_private_in_study_and_flashcards_and_absent_from_test_payloads_and_history(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Notes']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $word = $owner->words()->create(['term' => 'Apple', 'definition' => 'Fruit', 'note' => 'OWNER PRIVATE NOTE']);
        $c->words()->attach($word);
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert(['user_id' => $member->id, 'word_id' => $word->id, 'note' => 'MEMBER PRIVATE NOTE', 'created_at' => now(), 'updated_at' => now()]);
        $settings = ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1];
        foreach ([$owner, $member] as $learner) {
            $note = $learner->is($owner) ? 'OWNER PRIVATE NOTE' : 'MEMBER PRIVATE NOTE';
            $other = $learner->is($owner) ? 'MEMBER PRIVATE NOTE' : 'OWNER PRIVATE NOTE';
            $this->actingAs($learner);
            foreach (['study', 'flashcards', 'test'] as $mode) {
                $page = $this->get('/collections/'.$c->id.'/study?mode='.$mode.'&show_notes=1');
                $page->assertInertia(function (Assert $p) use ($mode, $note) {
                    if ($mode === 'test') {
                        $p->missing('words.0.note')->missing('collection.words.0.note');
                    } else {
                        $p->where('words.0.note', $note)->where('collection.words.0.note', $note);
                    }
                })->assertDontSee($other);
                $start = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => $mode, 'settings' => $settings, 'show_notes' => true])->assertCreated();
                if ($mode === 'test') {
                    $start->assertJsonMissingPath('questions.0.note')->assertDontSee($note);
                    $page->assertDontSee($note);
                } else {
                    $start->assertJsonPath('questions.0.note', $note);
                }
                $start->assertDontSee($other);
                $s = StudySession::findOrFail($start->json('id'));
                $this->assertStringNotContainsString('PRIVATE NOTE', json_encode($s->questions));
                $this->assertArrayNotHasKey('show_notes', $s->settings);
                if ($mode === 'test') {
                    $this->postJson('/collections/'.$c->id.'/sessions/'.$s->id.'/complete', ['answers' => [['word_id' => $word->id, 'attempt_no' => 1, 'answer' => 'Apple']]])->assertOk()->assertJsonMissingPath('results.0.note')->assertDontSee('PRIVATE NOTE');
                }
            }
            $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'test', 'settings' => $settings + ['show_notes' => true]])->assertUnprocessable();
            $this->get('/collections/'.$c->id)->assertInertia(fn (Assert $p) => $p->missing('summary.note'));
            // Public links stay note-free even for the signed-in owner or member.
            foreach (['study', 'test', 'flashcards'] as $mode) {
                $this->get('/s/'.$c->share_uuid.'/study?mode='.$mode.'&show_notes=1')->assertInertia(fn (Assert $p) => $p->missing('words.0.note')->missing('collection.words.0.note'))->assertDontSee('PRIVATE NOTE');
            }
        }
        auth()->logout();
        $this->get('/s/'.$c->share_uuid.'/study?mode=study')->assertInertia(fn (Assert $p) => $p->missing('words.0.note'))->assertDontSee('PRIVATE NOTE');
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study', 'settings' => $settings])->assertUnauthorized();
    }
}
