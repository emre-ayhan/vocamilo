<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(int $count = 3): array
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['title' => 'Learning']);
        $words = collect();
        for ($i = 0; $i < $count; $i++) {
            $words->push($user->words()->create(['term' => 'Term '.$i, 'definition' => 'Definition '.$i]));
        }
        $collection->words()->attach($words->pluck('id'));
        $this->actingAs($user);

        return [$user, $collection, $words];
    }

    private function start($collection, string $mode = 'study', array $settings = [], int $version = 2): array
    {
        $session = $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => $mode, 'settings' => array_replace(['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 3], $settings)])->assertCreated()->json();
        if ($mode === 'study') {
            $this->assertSame(3, $session['rules_version']);
            // Existing v2 regression scenarios represent already-open sessions.
            if ($version === 2) {
                StudySession::findOrFail($session['id'])->update(['rules_version' => 2]);
                $session['rules_version'] = 2;
            }
        }

        return $session;
    }

    private function finish($collection, array $session, array $answers)
    {
        return $this->postJson('/collections/'.$collection->id.'/sessions/'.$session['id'].'/complete', ['answers' => $answers]);
    }

    private function answer(array $question, int $attempt = 1, ?string $value = null, bool $self = false): array
    {
        return ['word_id' => $question['id'], 'attempt_no' => $attempt, 'answer' => $value ?? $question['expected'], 'self_assessed' => $self];
    }

    public function test_wrong_answer_requires_two_extra_occurrences_and_score_stays_first_attempt(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $this->assertSame(2, $s['rules_version']);
        [$a, $b, $d] = $s['questions'];
        $initial = [$this->answer($a, 1, 'wrong'), $this->answer($b), $this->answer($d)];
        $this->finish($c, $s, $initial)->assertUnprocessable();
        $this->finish($c, $s, [...$initial, $this->answer($a, 2)])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $answers = [...$initial, $this->answer($a, 2), $this->answer($a, 3)];
        $response = $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.correct', 2)->assertJsonPath('session.total', 3)->assertJsonPath('session.percent', 67)->assertJsonPath('session.repeat_count', 2)->assertJsonPath('session.total_attempts', 5)->assertJsonCount(5, 'results')->json();
        $this->assertTrue($response['results'][0]['first_attempt']);
        $this->assertFalse($response['results'][3]['first_attempt']);
        $this->assertSame(3, $response['results'][4]['attempt_no']);
        $this->finish($c, $s, [])->assertOk()->assertExactJson($response);
        $this->assertDatabaseCount('study_answers', 5);
    }

    public function test_repeated_wrong_only_extends_when_last_pending_occurrence_is_wrong(): void
    {
        [, $c] = $this->fixture(1);
        $s = $this->start($c);
        $q = $s['questions'][0];
        $three = [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'wrong'), $this->answer($q, 3, 'wrong')];
        $this->finish($c, $s, $three)->assertUnprocessable();
        $this->finish($c, $s, [...$three, $this->answer($q, 4)])->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.repeat_count', 3);
        $other = $this->start($c);
        $this->finish($c, $other, [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'wrong'), $this->answer($q, 3)])->assertOk()->assertJsonPath('session.repeat_count', 2);
    }

    public function test_forged_reordered_duplicate_missing_or_extra_attempts_are_rejected_atomically(): void
    {
        [, $c] = $this->fixture(2);
        $s = $this->start($c);
        [$a, $b] = $s['questions'];
        $valid = [$this->answer($a, 1, 'wrong'), $this->answer($b), $this->answer($a, 2), $this->answer($a, 3)];
        $duplicate = $valid;
        $duplicate[3]['attempt_no'] = 2;
        $missing = $valid;
        unset($missing[0]['attempt_no']);
        $outOfOrder = [$valid[0], $valid[2], $valid[1], $valid[3]];
        $skip = $valid;
        $skip[2]['attempt_no'] = 3;
        foreach ([$duplicate, $missing, $outOfOrder, $skip, [...$valid, $this->answer($a, 4)]] as $forged) {
            $this->finish($c, $s, $forged)->assertUnprocessable();
            $this->assertDatabaseCount('study_answers', 0);
            $this->assertNull(StudySession::findOrFail($s['id'])->completed_at);
        }
        $this->finish($c, $s, $valid)->assertOk();
    }

    public function test_all_correct_once_and_mixed_written_definition_self_assessment(): void
    {
        [, $c] = $this->fixture(2);
        $s = $this->start($c, 'study', ['direction' => 'definition']);
        [$a, $b] = $s['questions'];
        $this->finish($c, $s, [$this->answer($a, 1, 'my equivalent definition', true), $this->answer($b, 1, 'wrong'), $this->answer($b, 2), $this->answer($b, 3, 'equivalent', true)])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.percent', 50);
        $s = $this->start($c);
        $this->finish($c, $s, array_map(fn ($q) => $this->answer($q), $s['questions']))->assertOk()->assertJsonPath('session.correct', 2)->assertJsonPath('session.repeat_count', 0);
    }

    public function test_snapshot_order_and_expected_values_survive_edits_and_reject_selfassessment_for_terms(): void
    {
        [, $c, $words] = $this->fixture(2);
        $s = $this->start($c);
        [$a, $b] = $s['questions'];
        $words[0]->update(['term' => 'Changed']);
        $this->finish($c, $s, [$this->answer($a, 1, 'wrong', true), $this->answer($b)])->assertUnprocessable();
        $this->finish($c, $s, [$this->answer($a), $this->answer($b)])->assertOk()->assertJsonPath('session.correct', 2);
    }

    public function test_test_and_flashcards_keep_single_answer_strict_validation(): void
    {
        [, $c] = $this->fixture(1);
        foreach (['test', 'flashcards'] as $mode) {
            $s = $this->start($c, $mode);
            if ($mode === 'test') {
                StudySession::findOrFail($s['id'])->update(['rules_version' => 1]);
            }
            $q = $s['questions'][0];
            $answer = $mode === 'test' ? ['word_id' => $q['id'], 'answer' => $q['expected']] : ['word_id' => $q['id'], 'known' => true];
            $this->finish($c, $s, [$answer, $answer])->assertUnprocessable();
            $this->finish($c, $s, [$answer + ['attempt_no' => 1]])->assertUnprocessable();
            $this->finish($c, $s, [$answer])->assertOk()->assertJsonPath('session.correct', 1);
        }
    }

    public function test_legacy_study_sessions_complete_once_without_attempt_numbers(): void
    {
        [, $c] = $this->fixture(1);
        $s = $this->start($c);
        StudySession::findOrFail($s['id'])->update(['rules_version' => 1]);
        $this->finish($c, $s, [['word_id' => $s['questions'][0]['id'], 'answer' => 'wrong']])->assertOk()->assertJsonPath('session.correct', 0);
        $this->assertDatabaseCount('study_answers', 1);
    }

    public function test_multiple_wrong_words_interleave_each_repeat_round_in_snapshot_queue_order(): void
    {
        [, $c] = $this->fixture(3);
        $s = $this->start($c);
        [$a, $b, $d] = $s['questions'];
        $initial = [$this->answer($a, 1, 'wrong'), $this->answer($b, 1, 'wrong'), $this->answer($d)];
        $this->finish($c, $s, [...$initial, $this->answer($a, 2), $this->answer($a, 3), $this->answer($b, 2), $this->answer($b, 3)])->assertUnprocessable();
        $this->finish($c, $s, [...$initial, $this->answer($a, 2), $this->answer($b, 2), $this->answer($a, 3), $this->answer($b, 3)])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.repeat_count', 4);
    }

    public function test_v3_stops_after_correct_first_or_second_attempt_and_preserves_first_score(): void
    {
        [, $c] = $this->fixture(1);
        $s = $this->start($c, 'study', [], 3);
        $q = $s['questions'][0];
        $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2)])->assertUnprocessable();
        $this->finish($c, $s, [$this->answer($q)])->assertOk()->assertJsonPath('session.repeat_count', 0)->assertJsonPath('session.correct', 1);
        $s = $this->start($c, 'study', [], 3);
        $answers = [$this->answer($q, 1, 'wrong'), $this->answer($q, 2)];
        $this->finish($c, $s, [$answers[0]])->assertUnprocessable();
        $this->finish($c, $s, [...$answers, $this->answer($q, 3)])->assertUnprocessable();
        $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.total', 1)->assertJsonPath('session.repeat_count', 1)->assertJsonCount(1, 'first_results');
    }

    public function test_v3_third_attempt_finishes_whether_correct_or_wrong_and_fourth_is_rejected(): void
    {
        [, $c] = $this->fixture(1);
        foreach (['wrong', null] as $last) {
            $s = $this->start($c, 'study', [], 3);
            $q = $s['questions'][0];
            $answers = [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'wrong'), $this->answer($q, 3, $last)];
            $this->finish($c, $s, array_slice($answers, 0, 2))->assertUnprocessable();
            $this->finish($c, $s, [...$answers, $this->answer($q, 4)])->assertUnprocessable();
            $result = $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.rules_version', 3)->assertJsonPath('session.correct', 0)->assertJsonPath('session.total_attempts', 3)->assertJsonPath('session.repeat_count', 2)->assertJsonPath('results.2.correct', $last === null)->json();
            $this->finish($c, $s, [])->assertOk()->assertExactJson($result);
        }
    }

    public function test_v3_interleaves_only_still_incorrect_words_and_keeps_priority_first_attempt(): void
    {
        [, $c] = $this->fixture(3);
        $s = $this->start($c, 'study', [], 3);
        [$a, $b, $d] = $s['questions'];
        $answers = [$this->answer($a, 1, 'wrong'), $this->answer($b, 1, 'wrong'), $this->answer($d), $this->answer($a, 2), $this->answer($b, 2, 'wrong'), $this->answer($b, 3, 'wrong')];
        $forged = $answers;
        $forged[3]['attempt_no'] = 3;
        $this->finish($c, $s, $forged)->assertUnprocessable();
        $forged = $answers;
        [$forged[3], $forged[4]] = [$forged[4], $forged[3]];
        $this->finish($c, $s, $forged)->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.total', 3)->assertJsonPath('session.repeat_count', 3);
        $this->assertSame([$a['id'], $b['id']], array_column($this->start($c, 'study', ['count' => 2], 3)['questions'], 'id'));
    }

    private function history($user, $collection, $word, bool $correct, int $minutesAgo = 1, string $mode = 'study', bool $completed = true): void
    {
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'collection_id' => $collection->id, 'mode' => $mode, 'settings' => [], 'questions' => [['id' => $word->id]], 'total_count' => 1, 'correct_count' => (int) $correct, 'completed_at' => $completed ? now()->subMinutes($minutesAgo) : null]);
        $session->answers()->create(['word_id' => $word->id, 'position' => 0, 'attempt_no' => 1, 'prompt' => $word->definition, 'expected' => $word->term, 'answer' => $correct ? $word->term : 'wrong', 'correct' => $correct, 'self_assessed' => false]);
    }

    public function test_latest_first_attempt_prioritizes_unknown_and_unseen_then_known_fills_count(): void
    {
        [$user, $c, $words] = $this->fixture(4);
        $this->history($user, $c, $words[0], true);
        $this->history($user, $c, $words[1], true, 5);
        $this->history($user, $c, $words[1], false, 1);
        $this->history($user, $c, $words[3], false, 5);
        $this->history($user, $c, $words[3], true, 1);
        $this->assertSame([$words[1]->id, $words[2]->id, $words[0]->id], array_column($this->start($c)['questions'], 'id'));
        $this->assertSame([$words[1]->id, $words[2]->id], array_column($this->start($c, 'study', ['count' => 2])['questions'], 'id'));
        $random = array_column($this->start($c, 'study', ['order' => 'random', 'count' => 4])['questions'], 'id');
        $this->assertEqualsCanonicalizing([$words[1]->id, $words[2]->id], array_slice($random, 0, 2));
        $this->assertEqualsCanonicalizing([$words[0]->id, $words[3]->id], array_slice($random, 2));
        $this->assertSame($words->take(3)->pluck('id')->all(), array_column($this->start($c, 'test')['questions'], 'id'));
        $this->assertSame($words->take(3)->pluck('id')->all(), array_column($this->start($c, 'flashcards')['questions'], 'id'));
    }

    public function test_correct_retries_do_not_override_first_wrong_priority(): void
    {
        [, $c] = $this->fixture(3);
        $session = $this->start($c);
        [$a, $b, $d] = $session['questions'];
        $this->finish($c, $session, [$this->answer($a), $this->answer($b, 1, 'wrong'), $this->answer($d), $this->answer($b, 2), $this->answer($b, 3)])->assertOk();
        $this->assertSame($b['id'], $this->start($c, 'study', ['count' => 1])['questions'][0]['id']);
        $this->travel(1)->minutes();
        $latest = $this->start($c, 'study', ['count' => 1]);
        $this->finish($c, $latest, [$this->answer($latest['questions'][0])])->assertOk();
        $this->assertSame($a['id'], $this->start($c, 'study', ['count' => 1])['questions'][0]['id']);
    }

    public function test_priority_is_private_to_learner_collection_and_completed_study_only(): void
    {
        [$owner, $c, $words] = $this->fixture(3);
        $member = User::factory()->create();
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $c->members()->attach($member);
        $otherCollection = $owner->collections()->create(['title' => 'Other context']);
        $otherCollection->words()->attach($words->pluck('id'));
        $this->history($owner, $c, $words[0], true);
        $this->history($member, $otherCollection, $words[0], true);
        $this->history($member, $c, $words[0], true, 1, 'test');
        $this->history($member, $c, $words[0], true, 1, 'study', false);
        $this->actingAs($member);
        $s = $this->start($c);
        $this->assertSame($words->pluck('id')->all(), array_column($s['questions'], 'id'));
        $this->history($member, $c, $words[1], true);
        $this->assertSame([$words[0]->id, $words[2]->id, $words[1]->id], array_column($this->start($c)['questions'], 'id'));
        $this->actingAs($owner);
        $this->assertSame([$words[1]->id, $words[2]->id, $words[0]->id], array_column($this->start($c)['questions'], 'id'));
        $this->finish($c, $s, array_map(fn ($q) => $this->answer($q), $s['questions']))->assertNotFound();
        $count = StudySession::count();
        $this->post('/logout');
        $this->get('/s/'.$c->share_uuid.'/study?mode=study')->assertOk();
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study'])->assertUnauthorized();
        $this->assertSame($count, StudySession::count());
    }
}
