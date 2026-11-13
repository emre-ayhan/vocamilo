<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudySessionTest extends TestCase
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
        $collection = $owner->collections()->create(['title' => 'Spelling']);
        $first = $owner->words()->create(['term' => 'Bright', 'definition' => 'full of light', 'note' => 'private note']);
        $second = $owner->words()->create(['term' => 'Calm', 'definition' => 'peaceful and quiet']);
        $collection->words()->attach([$first->id, $second->id]);

        return [$owner, $collection, $first, $second];
    }

    public function test_summary_counts_only_completed_owner_sessions_and_separates_modes(): void
    {
        [$owner, $collection] = $this->fixture();
        $base = ['user_id' => $owner->id, 'collection_id' => $collection->id, 'mode' => 'test', 'settings' => [], 'questions' => [], 'total_count' => 2];
        StudySession::create(['id' => (string) Str::uuid()] + $base);
        StudySession::create(['id' => (string) Str::uuid(), 'correct_count' => 1, 'completed_at' => now()->subMinute()] + $base);
        $latest = StudySession::create(['id' => (string) Str::uuid(), 'correct_count' => 2, 'completed_at' => now()] + $base);
        StudySession::create(['id' => (string) Str::uuid(), 'mode' => 'study', 'correct_count' => 1, 'completed_at' => now(), 'self_assessed_count' => 1] + $base);
        StudySession::create(['id' => (string) Str::uuid(), 'mode' => 'flashcards', 'correct_count' => 1, 'completed_at' => now(), 'self_assessed_count' => 2] + $base);
        $foreign = User::factory()->create();
        StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $foreign->id, 'correct_count' => 2, 'completed_at' => now()] + $base);

        $summary = StudySession::summaryFor($collection);
        $this->assertSame(4, $summary['total_sessions']);
        $this->assertSame(2, $summary['modes']['test']['count']);
        $this->assertEquals(100, $summary['modes']['test']['best_percent']);
        $this->assertSame($latest->id, $summary['modes']['test']['last']['id']);
        $this->assertSame(1, $summary['modes']['study']['count']);
        $this->assertSame(1, $summary['modes']['flashcards']['count']);
        $this->assertCount(4, $summary['recent']);
    }

    private function settings(array $overrides = []): array
    {
        return array_replace(['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 2], $overrides);
    }

    private function start($collection, string $mode = 'study', array $settings = [], array $extra = []): array
    {
        return $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => $mode, 'settings' => $this->settings($settings)] + $extra)->assertCreated()->json();
    }

    private function finish($collection, array $session, array $answers)
    {
        return $this->postJson('/collections/'.$collection->id.'/sessions/'.$session['id'].'/complete', ['answers' => $answers]);
    }

    private function correctAnswers(array $session): array
    {
        return array_map(fn ($q) => ['word_id' => $q['id'], 'answer' => $q['expected']], $session['questions']);
    }

    public function test_guests_cannot_create_or_complete_persisted_sessions(): void
    {
        [$owner, $collection] = $this->fixture();
        $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'study', 'settings' => $this->settings()])->assertUnauthorized();
        $this->postJson('/collections/'.$collection->id.'/sessions/'.Str::uuid().'/complete', ['answers' => []])->assertUnauthorized();
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_start_and_completion_are_strictly_owner_and_collection_scoped(): void
    {
        [$owner, $collection] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $session = $this->start($collection);
        $secondCollection = $owner->collections()->create(['title' => 'Other set']);
        $this->finish($secondCollection, $session, $this->correctAnswers($session))->assertNotFound();
        $this->actingAs($other)->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'study', 'settings' => $this->settings()])->assertNotFound();
        $this->finish($collection, $session, $this->correctAnswers($session))->assertNotFound();
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertDatabaseCount('study_answers', 0);
        $this->assertNull(StudySession::findOrFail($session['id'])->completed_at);
    }

    public function test_start_selects_owned_collection_words_and_validates_settings(): void
    {
        [$owner, $collection, $first, $second] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'study', ['count' => 2000]);
        $this->assertSame([$first->id, $second->id], array_column($session['questions'], 'id'));
        $this->assertSame('full of light', $session['questions'][0]['prompt']);
        $this->assertSame('Bright', $session['questions'][0]['expected']);
        $this->assertSame('private note', $session['questions'][0]['note']);
        $this->assertArrayNotHasKey('note', StudySession::findOrFail($session['id'])->questions[0]);
        $subset = $this->start($collection, 'study', [], ['word_ids' => [$second->id], 'practice_round' => true]);
        $this->assertCount(1, $subset['questions']);
        $this->assertSame($second->id, $subset['questions'][0]['id']);
        $this->assertTrue(StudySession::findOrFail($subset['id'])->practice_round);
        $unattached = $owner->words()->create(['term' => 'Elsewhere', 'definition' => 'not here']);
        $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'study', 'settings' => $this->settings(), 'word_ids' => [$unattached->id]])->assertUnprocessable();
        $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => 'invalid', 'settings' => $this->settings(['count' => 0])])->assertUnprocessable();
        $empty = $owner->collections()->create(['title' => 'Empty']);
        $this->postJson('/collections/'.$empty->id.'/sessions', ['mode' => 'study', 'settings' => $this->settings()])->assertUnprocessable();
    }

    public function test_server_snapshot_scoring_ignores_later_word_edits_and_client_score(): void
    {
        [$owner, $collection, $first, $second] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test');
        $first->update(['term' => 'Changed']);
        $answers = [['word_id' => $first->id, 'answer' => '  ＢＲＩＧＨＴ  '], ['word_id' => $second->id, 'answer' => 'wrong']];
        $this->postJson('/collections/'.$collection->id.'/sessions/'.$session['id'].'/complete', ['answers' => $answers, 'correct' => 2, 'percent' => 100])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.incorrect', 1)->assertJsonPath('session.percent', 50)->assertJsonPath('results.0.expected', 'Bright');
        $this->assertDatabaseHas('study_answers', ['study_session_id' => $session['id'], 'expected' => 'Bright', 'correct' => true]);
    }

    public function test_completing_twice_is_idempotent_even_with_different_answers(): void
    {
        [$owner, $collection] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test');
        $result = $this->finish($collection, $session, $this->correctAnswers($session))->assertOk()->json();
        $second = $this->finish($collection, $session, [])->assertOk()->json();
        $this->assertSame($result, $second);
        $this->assertDatabaseCount('study_answers', 2);
        $this->assertSame(1, StudySession::summaryFor($collection)['total_sessions']);
    }

    public function test_duplicate_missing_and_unrelated_answers_are_rejected_atomically(): void
    {
        [$owner, $collection, $first, $second] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection);
        $this->finish($collection, $session, [['word_id' => $first->id, 'answer' => 'Bright']])->assertUnprocessable();
        $this->finish($collection, $session, [['word_id' => $first->id, 'answer' => 'Bright'], ['word_id' => $first->id, 'answer' => 'Bright']])->assertUnprocessable();
        $this->finish($collection, $session, [['word_id' => $first->id, 'answer' => 'Bright'], ['word_id' => 99999, 'answer' => 'Calm']])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $this->assertNull(StudySession::findOrFail($session['id'])->completed_at);
    }

    public function test_invalid_second_answer_rolls_back_first_answer_write(): void
    {
        [$owner, $collection, $first, $second] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test');
        $this->finish($collection, $session, [['word_id' => $first->id, 'answer' => 'Bright'], ['word_id' => $second->id, 'answer' => 'wrong', 'self_assessed' => true]])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $this->assertNull(StudySession::findOrFail($session['id'])->completed_at);
    }

    public function test_written_definition_self_assessment_is_allowed_only_in_study(): void
    {
        [$owner, $collection] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'study', ['direction' => 'definition', 'count' => 1]);
        $this->finish($collection, $session, [['word_id' => $session['questions'][0]['id'], 'answer' => 'my own accurate explanation', 'self_assessed' => true]])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.self_assessed', 1)->assertJsonPath('results.0.self_assessed', true);
        foreach ([['mode' => 'test', 'type' => 'written', 'direction' => 'definition'], ['mode' => 'study', 'type' => 'choice', 'direction' => 'definition'], ['mode' => 'study', 'type' => 'written', 'direction' => 'term']] as $case) {
            $s = $this->start($collection, $case['mode'], ['direction' => $case['direction'], 'type' => $case['type'], 'count' => 1]);
            $this->finish($collection, $s, [['word_id' => $s['questions'][0]['id'], 'answer' => $s['questions'][0]['expected'], 'self_assessed' => true]])->assertUnprocessable();
        }
    }

    public function test_flashcard_known_marks_are_required_and_separately_labeled(): void
    {
        [$owner, $collection, $first, $second] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'flashcards');
        $this->finish($collection, $session, $this->correctAnswers($session))->assertUnprocessable();
        $this->finish($collection, $session, [['word_id' => $first->id, 'known' => true], ['word_id' => $second->id, 'known' => false]])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.self_assessed', 2)->assertJsonPath('results.1.correct', false);
        $study = $this->start($collection, 'study', ['count' => 1]);
        $this->finish($collection, $study, [['word_id' => $study['questions'][0]['id'], 'answer' => 'Bright', 'known' => true]])->assertUnprocessable();
    }

    public function test_choice_answers_must_come_from_offered_options_and_use_snapshot(): void
    {
        [$owner, $collection] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test', ['type' => 'choice', 'count' => 1]);
        $question = $session['questions'][0];
        $this->assertSame('choice', $question['type']);
        $this->assertCount(2, $question['options']);
        $this->finish($collection, $session, [['word_id' => $question['id'], 'answer' => 'not an option']])->assertUnprocessable();
        $wrong = collect($question['options'])->first(fn ($v) => $v !== $question['expected']);
        $this->finish($collection, $session, [['word_id' => $question['id'], 'answer' => $wrong]])->assertOk()->assertJsonPath('session.correct', 0);
    }

    public function test_single_word_choice_falls_back_to_written_and_normalizes_whitespace(): void
    {
        [$owner, $collection, $first] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test', ['type' => 'choice', 'direction' => 'definition'], ['word_ids' => [$first->id]]);
        $this->assertSame('written', $session['questions'][0]['type']);
        $this->finish($collection, $session, [['word_id' => $first->id, 'answer' => " FULL   OF\nLIGHT "]])->assertOk()->assertJsonPath('session.correct', 1);
    }

    public function test_word_deletion_keeps_snapshot_and_does_not_break_completion(): void
    {
        [$owner, $collection, $first] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test');
        $firstId = $first->id;
        $first->delete();
        $this->finish($collection, $session, $this->correctAnswers($session))->assertOk()->assertJsonPath('session.correct', 2)->assertJsonPath('results.0.expected', 'Bright');
        $this->assertDatabaseHas('study_answers', ['study_session_id' => $session['id'], 'position' => 0, 'word_id' => null, 'expected' => 'Bright']);
        $this->assertSame($firstId, StudySession::findOrFail($session['id'])->questions[0]['id']);
        $collection->delete();
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertDatabaseCount('study_answers', 0);
    }

    public function test_deleting_user_cascades_persisted_study_history(): void
    {
        [$owner, $collection] = $this->fixture();
        $this->actingAs($owner);
        $session = $this->start($collection, 'test');
        $this->finish($collection, $session, $this->correctAnswers($session))->assertOk();
        $owner->delete();
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertDatabaseCount('study_answers', 0);
    }
}
