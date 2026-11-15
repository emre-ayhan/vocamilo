<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count = 1): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Feedback']);
        for ($i = 0; $i < $count; $i++) {
            $c->words()->attach($u->words()->create(['term' => 'word'.$i, 'definition' => 'meaning'.$i])->id);
        }
        $this->actingAs($u);

        return [$u, $c];
    }

    private function start($c, string $mode = 'test', string $type = 'rearrange', string $direction = 'term'): array
    {
        $session = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => $mode, 'settings' => ['type' => $type, 'direction' => $direction, 'order' => 'ordered', 'count' => 20]])->assertCreated()->assertJsonPath('rules_version', $mode === 'study' ? 7 : 5)->json();
        // Preserve historical protocol regressions for already-open sessions.
        if ($mode === 'study') {
            StudySession::findOrFail($session['id'])->update(['rules_version' => 6]);
            $session['rules_version'] = 6;
        }

        return $session;
    }

    private function answer($q, int $n = 1, array $flags = []): array
    {
        return $flags + ['word_id' => $q['id'], 'attempt_no' => $n, 'answer' => $q['expected']];
    }

    private function finish($c, $s, $answers)
    {
        return $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $answers]);
    }

    public function test_test_first_skip_is_separate_but_still_in_denominator_and_idempotent(): void
    {
        [, $c] = $this->fixture(2);
        $s = $this->start($c);
        [$a,$b] = $s['questions'];
        $answers = [$this->answer($a, 1, ['answer' => null, 'skipped' => true]), $this->answer($b)];
        $r = $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.total', 2)->assertJsonPath('session.correct', 1)->assertJsonPath('session.skipped', 1)->assertJsonPath('session.incorrect', 0)->assertJsonPath('session.percent', 50)->assertJsonPath('session.total_attempts', 2)->assertJsonPath('mastery', [])->json();
        $this->finish($c, $s, $answers)->assertExactJson($r);
        $this->assertDatabaseCount('study_answers', 2);
        $this->assertTrue($r['results'][0]['skipped']);
    }

    public function test_test_retry_skip_stops_retries_without_reclassifying_first_wrong(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->finish($c, $s, [$this->answer($q, 1, ['answer' => 'wrong']), $this->answer($q, 2, ['answer' => '', 'skipped' => true])])->assertOk()->assertJsonPath('session.skipped', 0)->assertJsonPath('session.incorrect', 1)->assertJsonPath('session.percent', 0)->assertJsonPath('session.total_attempts', 2)->assertJsonPath('results.1.skipped', true)->assertJsonPath('mastery', []);
        $next = $this->start($c, 'study');
        $this->assertSame('new', $next['questions'][0]['prior_stage']);
    }

    public function test_study_taint_cannot_be_cleared_by_correct_answer_or_self_assessment(): void
    {
        foreach (['written', 'rearrange'] as $type) {
            [, $c] = $this->fixture();
            $s = $this->start($c, 'study', $type, 'definition');
            $q = $s['questions'][0];
            $flags = ['had_error' => true];
            if ($type === 'written') {
                $flags['self_assessed'] = true;
            }
            $answers = [$this->answer($q, 1, $flags), $this->answer($q, 2), $this->answer($q, 3)];
            $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('results.0.correct', false)->assertJsonPath('results.0.had_error', true)->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', true);
            $next = $this->start($c, 'study', $type);
            $q = $next['questions'][0];
            $this->assertTrue($q['prior_qualified']);
            $this->finish($c, $next, [$this->answer($q), $this->answer($q, 2)])->assertOk()->assertJsonPath('mastery.0.stage', 'reinforced');
        }
    }

    public function test_invalid_skip_error_combinations_are_atomic_and_do_not_complete(): void
    {
        [, $c] = $this->fixture();
        $test = $this->start($c);
        $q = $test['questions'][0];
        foreach ([['skipped' => true], ['skipped' => true, 'answer' => null, 'self_assessed' => true], ['had_error' => true], ['skipped' => 'yes', 'answer' => null]] as $flags) {
            $this->finish($c, $test, [$this->answer($q, 1, $flags)])->assertUnprocessable();
        }
        $study = $this->start($c, 'study');
        $q = $study['questions'][0];
        $this->finish($c, $study, [$this->answer($q, 1, ['answer' => null, 'skipped' => true])])->assertUnprocessable();
        $this->finish($c, $study, [$this->answer($q, 1, ['self_assessed' => true])])->assertUnprocessable();
        $choice = $this->start($c, 'study', 'choice');
        $q = $choice['questions'][0];
        $this->finish($c, $choice, [$this->answer($q, 1, ['had_error' => true])])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $this->assertSame(0, StudySession::whereNotNull('completed_at')->count());
    }

    public function test_legacy_test_rejects_new_flags_and_retains_single_correct_completion(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c, 'test', 'written');
        $q = $s['questions'][0];
        StudySession::find($s['id'])->update(['rules_version' => 3]);
        $this->finish($c, $s, [$this->answer($q, 1, ['skipped' => true, 'answer' => null])])->assertUnprocessable();
        $this->finish($c, $s, [$this->answer($q, 1, ['had_error' => false])])->assertUnprocessable();
        $this->finish($c, $s, [$this->answer($q)])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.total_attempts', 1)->assertJsonPath('session.skipped', 0)->assertJsonPath('results.0.had_error', false)->assertJsonPath('results.0.skipped', false);
    }

    public function test_v4_mastery_is_retained_in_v5_and_cross_user_completion_is_denied(): void
    {
        [$owner,$c] = $this->fixture();
        $s = $this->start($c, 'study', 'written');
        $q = $s['questions'][0];
        StudySession::find($s['id'])->update(['rules_version' => 4]);
        $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2)])->assertOk();
        $v5 = $this->start($c, 'study', 'rearrange');
        $q = $v5['questions'][0];
        $this->assertSame('learning', $q['prior_stage']);
        $this->assertSame($s['id'], $q['prior_baseline']);
        $this->actingAs(User::factory()->create());
        $this->finish($c, $v5, [$this->answer($q), $this->answer($q, 2)])->assertNotFound();
        $this->actingAs($owner);
        $this->finish($c, $v5, [$this->answer($q), $this->answer($q, 2)])->assertOk()->assertJsonPath('mastery.0.stage', 'reinforced');
    }
}
