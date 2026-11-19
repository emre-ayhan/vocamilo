<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyRandomRepeatTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['title' => 'Random repeats']);
        foreach (['alpha', 'beta', 'gamma'] as $term) {
            $collection->words()->attach($user->words()->create(['term' => $term, 'definition' => $term.' definition'])->id);
        }
        $this->actingAs($user);
        $session = $this->postJson('/collections/'.$collection->id.'/sessions', [
            'mode' => 'study', 'settings' => ['type' => 'choice', 'types' => ['choice'], 'direction' => 'term', 'order' => 'ordered', 'count' => 3],
        ])->assertCreated()->assertJsonPath('rules_version', 8)->json();

        return [$collection, $session];
    }

    private function answer(array $question, int $attempt, bool $correct = true): array
    {
        $answer = $correct ? $question['expected'] : collect($question['options'])->first(fn ($option) => $option !== $question['expected']);

        return ['word_id' => $question['id'], 'attempt_no' => $attempt, 'answer' => $answer];
    }

    private function finish($collection, $session, $answers)
    {
        return $this->postJson('/collections/'.$collection->id.'/sessions/'.$session['id'].'/complete', ['answers' => $answers]);
    }

    public function test_mixed_correct_and_wrong_repeats_save_in_shuffled_order_with_first_score_preserved(): void
    {
        [$collection, $session] = $this->fixture();
        [$a, $b, $c] = $session['questions'];
        $answers = [
            $this->answer($a, 1), $this->answer($b, 1, false), $this->answer($c, 1),
            $this->answer($c, 2), $this->answer($b, 2), $this->answer($a, 2), $this->answer($b, 3),
        ];
        $this->finish($collection, $session, $answers)->assertOk()
            ->assertJsonPath('session.correct', 2)->assertJsonPath('session.total_attempts', 7)
            ->assertJsonPath('results.3.id', $c['id'])->assertJsonPath('results.4.id', $b['id']);
        $this->assertDatabaseCount('study_answers', 7);
    }

    public function test_shuffle_cannot_skip_first_questions_omit_due_retries_or_add_completed_retries(): void
    {
        [$collection, $session] = $this->fixture();
        [$a, $b, $c] = $session['questions'];
        $initial = [$this->answer($a, 1), $this->answer($b, 1), $this->answer($c, 1)];
        foreach ([
            [$this->answer($b, 1), $this->answer($a, 1), $this->answer($c, 1)],
            [...$initial, $this->answer($c, 2), $this->answer($b, 2)],
            [...$initial, $this->answer($c, 2), $this->answer($b, 2), $this->answer($c, 3)],
            [...$initial, $this->answer($c, 3), $this->answer($b, 2), $this->answer($a, 2)],
        ] as $answers) {
            $this->finish($collection, $session, $answers)->assertUnprocessable();
            $this->assertDatabaseCount('study_answers', 0);
        }
    }

    public function test_already_open_v7_sessions_keep_strict_repeat_order(): void
    {
        [$collection, $session] = $this->fixture();
        StudySession::findOrFail($session['id'])->update(['rules_version' => 7]);
        [$a, $b, $c] = $session['questions'];
        $initial = [$this->answer($a, 1), $this->answer($b, 1), $this->answer($c, 1)];
        $this->finish($collection, $session, [...$initial, $this->answer($b, 2), $this->answer($a, 2), $this->answer($c, 2)])->assertUnprocessable();
        $this->finish($collection, $session, [...$initial, $this->answer($a, 2), $this->answer($b, 2), $this->answer($c, 2)])->assertOk();
    }
}
