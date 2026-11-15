<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyAutoCheckTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count = 1): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Study skips']);
        for ($i = 0; $i < $count; $i++) {
            $c->words()->attach($u->words()->create(['term' => 'word'.$i, 'definition' => 'meaning'.$i])->id);
        }
        $this->actingAs($u);

        return [$u, $c];
    }

    private function start($c, string $type = 'written', string $mode = 'study'): array
    {
        return $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => $mode, 'settings' => ['type' => $type, 'direction' => 'term', 'order' => 'ordered', 'count' => 20]])->assertCreated()->assertJsonPath('rules_version', $mode === 'study' ? 6 : 5)->json();
    }

    private function answer($q, int $n = 1, array $flags = []): array
    {
        return $flags + ['word_id' => $q['id'], 'attempt_no' => $n, 'answer' => $q['expected']];
    }

    private function skip($q, int $n = 1): array
    {
        return $this->answer($q, $n, ['answer' => null, 'skipped' => true]);
    }

    private function finish($c, $s, $answers)
    {
        return $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $answers]);
    }

    public function test_first_skip_requeues_behind_other_words_and_cannot_be_saved_early(): void
    {
        [, $c] = $this->fixture(2);
        $s = $this->start($c);
        [$a,$b] = $s['questions'];
        $this->finish($c, $s, [$this->skip($a), $this->answer($b)])->assertUnprocessable();
        $this->finish($c, $s, [$this->skip($a), $this->answer($a, 2), $this->answer($b)])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $answers = [$this->skip($a), $this->answer($b), $this->answer($a, 2), $this->answer($b, 2), $this->answer($a, 3)];
        $r = $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.skipped', 1)->assertJsonPath('session.total', 2)->assertJsonPath('session.percent', 50)->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', true)->json();
        $this->finish($c, $s, $answers)->assertExactJson($r);
        $this->assertDatabaseCount('study_answers', 5);
    }

    public function test_second_skip_stops_word_unqualified_in_both_supported_types(): void
    {
        foreach (['written', 'rearrange'] as $type) {
            [, $c] = $this->fixture();
            $s = $this->start($c, $type);
            $q = $s['questions'][0];
            $this->finish($c, $s, [$this->skip($q), $this->skip($q, 2), $this->answer($q, 3)])->assertUnprocessable();
            $this->finish($c, $s, [$this->skip($q), $this->skip($q, 2)])->assertOk()->assertJsonPath('session.total_attempts', 2)->assertJsonPath('session.correct', 0)->assertJsonPath('session.skipped', 1)->assertJsonPath('mastery.0.qualified', false);
        }
    }

    public function test_first_skip_at_third_presentation_requeues_and_separate_caps_bound_to_five(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $first = [$this->answer($q, 1, ['answer' => 'wrong']), $this->answer($q, 2, ['answer' => 'wrong']), $this->skip($q, 3)];
        $this->finish($c, $s, $first)->assertUnprocessable();
        $this->finish($c, $s, [...$first, $this->answer($q, 4)])->assertOk()->assertJsonPath('session.total_attempts', 4)->assertJsonPath('session.correct', 0)->assertJsonPath('session.skipped', 0)->assertJsonPath('session.incorrect', 1)->assertJsonPath('mastery.0.qualified', false);
        $s = $this->start($c);
        $q = $s['questions'][0];
        $five = [$this->skip($q), $this->answer($q, 2, ['answer' => 'wrong']), $this->answer($q, 3, ['answer' => 'wrong']), $this->skip($q, 4)];
        $this->finish($c, $s, [...$five, $this->answer($q, 5)])->assertUnprocessable();
        $this->finish($c, $s, $five)->assertOk()->assertJsonPath('session.total_attempts', 4)->assertJsonPath('mastery.0.qualified', false);
        $s = $this->start($c);
        $q = $s['questions'][0];
        $answers = [$this->skip($q), $this->answer($q, 2, ['answer' => 'wrong']), $this->answer($q, 3, ['answer' => 'wrong']), $this->answer($q, 4, ['answer' => 'wrong'])];
        $this->finish($c, $s, [...$answers, $this->skip($q, 5)])->assertUnprocessable();
        $this->finish($c, $s, $answers)->assertOk()->assertJsonPath('session.total_attempts', 4)->assertJsonPath('mastery.0.qualified', false);
    }

    public function test_skip_taints_prior_qualified_mastery_even_after_two_correct(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2)])->assertOk();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->assertTrue($q['prior_qualified']);
        $this->finish($c, $s, [$this->skip($q), $this->answer($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', true)->assertJsonPath('session.correct', 0);
    }

    public function test_choice_and_legacy_study_skips_rejected_but_test_skip_still_stops_immediately(): void
    {
        [, $c] = $this->fixture();
        $choice = $this->start($c, 'choice');
        $q = $choice['questions'][0];
        $this->finish($c, $choice, [$this->skip($q), $this->skip($q, 2)])->assertUnprocessable();
        $old = $this->start($c);
        StudySession::find($old['id'])->update(['rules_version' => 5]);
        $q = $old['questions'][0];
        $this->finish($c, $old, [$this->skip($q), $this->skip($q, 2)])->assertUnprocessable();
        $this->finish($c, $old, [$this->answer($q), $this->answer($q, 2)])->assertOk();
        $test = $this->start($c, 'written', 'test');
        $q = $test['questions'][0];
        $this->finish($c, $test, [$this->skip($q)])->assertOk()->assertJsonPath('session.total_attempts', 1)->assertJsonPath('session.skipped', 1)->assertJsonPath('mastery', []);
    }

    public function test_skip_flag_combinations_rejected_and_snapshot_retry_type_is_authoritative(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $q = $s['questions'][0];
        foreach ([['answer' => 'filled'], ['had_error' => true], ['self_assessed' => true]] as $bad) {
            $this->finish($c, $s, [array_replace($this->skip($q), $bad)])->assertUnprocessable();
        }
        $this->assertDatabaseCount('study_answers', 0);
        $s = $this->start($c, 'choice');
        $snapshot = $s['questions'];
        $snapshot[0]['retry_type'] = 'written';
        StudySession::find($s['id'])->update(['questions' => $snapshot]);
        $q = $snapshot[0];
        $this->finish($c, $s, [$this->answer($q), $this->skip($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.skipped', 0)->assertJsonPath('results.1.type', 'written')->assertJsonPath('mastery.0.qualified', true);
    }
}
