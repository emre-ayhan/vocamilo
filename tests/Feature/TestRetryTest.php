<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestRetryTest extends TestCase
{
    use RefreshDatabase;

    private function start(int $count = 1, array $settings = []): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Assessment']);
        for ($i = 0; $i < $count; $i++) {
            $w = $u->words()->create(['term' => 'word'.$i, 'definition' => 'meaning'.$i]);
            $c->words()->attach($w);
        }
        $this->actingAs($u);
        $s = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'test', 'settings' => array_replace(['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => $count], $settings)])->assertCreated()->assertJsonPath('rules_version', 3)->json();

        return [$c, $s];
    }

    private function answer($q, $attempt = 1, $right = true): array
    {
        return ['word_id' => $q['id'], 'attempt_no' => $attempt, 'answer' => $right ? $q['expected'] : 'wrong'];
    }

    private function complete($c, $s, $answers)
    {
        return $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $answers]);
    }

    public function test_new_test_stops_on_correct_second_and_scores_first_attempt_only(): void
    {
        [$c,$s] = $this->start();
        $q = $s['questions'][0];
        $a = [$this->answer($q, 1, false), $this->answer($q, 2)];
        $this->complete($c, $s, [$a[0]])->assertUnprocessable();
        $this->complete($c, $s, [...$a, $this->answer($q, 3)])->assertUnprocessable();
        $saved = $this->complete($c, $s, $a)->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.total', 1)->assertJsonPath('session.repeat_count', 1)->assertJsonCount(1, 'first_results')->json();
        $this->complete($c, $s, [])->assertExactJson($saved);
        $this->assertDatabaseCount('study_answers', 2);
    }

    public function test_three_wrong_stops_and_fourth_is_rejected(): void
    {
        [$c,$s] = $this->start();
        $q = $s['questions'][0];
        $a = [$this->answer($q, 1, false), $this->answer($q, 2, false), $this->answer($q, 3, false)];
        $this->complete($c, $s, [...$a, $this->answer($q, 4)])->assertUnprocessable();
        $this->complete($c, $s, $a)->assertOk()->assertJsonPath('session.repeat_count', 2)->assertJsonPath('results.2.correct', false);
    }

    public function test_mixed_test_combinations_are_balanced_and_score_server_snapshots(): void
    {
        [$c,$s] = $this->start(8, ['types' => ['choice', 'written'], 'directions' => ['term', 'definition']]);
        $counts = collect($s['questions'])->countBy(fn ($q) => $q['type'].':'.$q['answerKey']);
        $this->assertCount(4, $counts);
        foreach ($counts as $count) {
            $this->assertSame(2, $count);
        }
        $this->complete($c, $s, array_map(fn ($q) => $this->answer($q), $s['questions']))->assertOk()->assertJsonPath('session.correct', 8)->assertJsonPath('session.repeat_count', 0);
    }

    public function test_test_rejects_self_assessment_and_invalid_attempt_order(): void
    {
        [$c,$s] = $this->start(2, ['direction' => 'definition']);
        [$a,$b] = $s['questions'];
        $this->complete($c, $s, [$this->answer($a) + ['self_assessed' => true], $this->answer($b)])->assertUnprocessable();
        $this->complete($c, $s, [$this->answer($a, 1, false), $this->answer($a, 2), $this->answer($b)])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $this->complete($c, $s, [$this->answer($a, 1, false), $this->answer($b), $this->answer($a, 2)])->assertOk()->assertJsonPath('session.correct', 1);
    }

    public function test_legacy_test_v1_remains_single_pass(): void
    {
        [$c,$s] = $this->start();
        StudySession::findOrFail($s['id'])->update(['rules_version' => 1]);
        $this->complete($c, $s, [['word_id' => $s['questions'][0]['id'], 'answer' => 'wrong']])->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.total_attempts', 1);
    }
}
