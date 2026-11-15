<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyMasteryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(int $count = 1): array
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['title' => 'Mastery']);
        $words = collect();
        for ($i = 0; $i < $count; $i++) {
            $words->push($user->words()->create(['term' => 'Word '.$i, 'definition' => 'Definition '.$i]));
        }
        $collection->words()->attach($words->pluck('id'));
        $this->actingAs($user);

        return [$user, $collection, $words];
    }

    private function start($c, array $settings = [], array $extra = []): array
    {
        $session = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study', 'settings' => array_replace(['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 20], $settings)] + $extra)->assertCreated()->json();
        // Preserve historical protocol regressions for already-open sessions.
        if (true) {
            StudySession::findOrFail($session['id'])->update(['rules_version' => 6]);
            $session['rules_version'] = 6;
        }

        return $session;
    }

    private function answer(array $q, int $attempt = 1, ?string $answer = null): array
    {
        return ['word_id' => $q['id'], 'attempt_no' => $attempt, 'answer' => $answer ?? $q['expected']];
    }

    private function finish($c, array $s, array $answers)
    {
        return $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $answers]);
    }

    private function twice(array $s): array
    {
        return [...array_map(fn ($q) => $this->answer($q), $s['questions']), ...array_map(fn ($q) => $this->answer($q, 2), $s['questions'])];
    }

    public function test_new_requires_two_correct_then_later_completed_session_can_reinforce(): void
    {
        [, $c] = $this->fixture();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->assertSame(6, $s['rules_version']);
        $this->assertSame('new', $q['prior_stage']);
        $this->finish($c, $s, [$this->answer($q)])->assertUnprocessable();
        $r = $this->finish($c, $s, $this->twice($s))->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.total_attempts', 2)->json();
        $this->assertSame('learning', $r['mastery'][0]['stage']);
        $this->assertTrue($r['mastery'][0]['qualified']);
        $next = $this->start($c);
        $this->assertSame('learning', $next['questions'][0]['prior_stage']);
        $this->assertTrue($next['questions'][0]['prior_qualified']);
        $this->finish($c, $next, $this->twice($next))->assertOk()->assertJsonPath('mastery.0.stage', 'reinforced');
    }

    public function test_wrong_resets_qualification_caps_three_and_never_reinforces_that_session(): void
    {
        [, $c] = $this->fixture();
        $initial = $this->start($c);
        $this->finish($c, $initial, $this->twice($initial))->assertOk();
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->finish($c, $s, [$this->answer($q, 1, 'wrong'), $this->answer($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', true)->assertJsonPath('session.correct', 0);
        $s = $this->start($c);
        $q = $s['questions'][0];
        $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2, 'wrong'), $this->answer($q, 3)])->assertOk()->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', true);
        $s = $this->start($c);
        $q = $s['questions'][0];
        $three = [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'wrong'), $this->answer($q, 3, 'wrong')];
        $this->finish($c, $s, [...$three, $this->answer($q, 4)])->assertUnprocessable();
        $this->finish($c, $s, $three)->assertOk()->assertJsonPath('mastery.0.stage', 'learning')->assertJsonPath('mastery.0.qualified', false)->assertJsonPath('session.total_attempts', 3);
    }

    public function test_overlapping_starts_never_double_promote_and_stale_completion_preserves_new_wrong(): void
    {
        [, $c] = $this->fixture();
        $a = $this->start($c);
        $b = $this->start($c);
        $this->finish($c, $a, $this->twice($a))->assertOk()->assertJsonPath('mastery.0.stage', 'learning');
        $this->finish($c, $b, $this->twice($b))->assertOk()->assertJsonPath('mastery.0.applied', false);
        $old = $this->start($c);
        $new = $this->start($c);
        $q = $new['questions'][0];
        $this->finish($c, $new, [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'wrong'), $this->answer($q, 3)])->assertOk()->assertJsonPath('mastery.0.qualified', false);
        $result = $this->finish($c, $old, $this->twice($old))->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('mastery.0.applied', false)->json();
        $this->finish($c, $old, [])->assertOk()->assertExactJson($result);
        $next = $this->start($c);
        $this->assertFalse($next['questions'][0]['prior_qualified']);
        $this->assertSame('learning', $next['questions'][0]['prior_stage']);
    }

    public function test_retry_order_duplicates_and_snapshot_mutations_cannot_forge_completion(): void
    {
        [, $c, $words] = $this->fixture(2);
        $s = $this->start($c);
        [$a, $b] = $s['questions'];
        foreach ([[$this->answer($a), $this->answer($a, 2), $this->answer($b), $this->answer($b, 2)], [$this->answer($a), $this->answer($b), $this->answer($a), $this->answer($b, 2)]] as $bad) {
            $this->finish($c, $s, $bad)->assertUnprocessable();
        }
        $this->assertDatabaseCount('study_answers', 0);
        $words[0]->update(['term' => 'Edited after start']);
        $this->finish($c, $s, $this->twice($s))->assertOk()->assertJsonPath('session.correct', 2);
    }

    public function test_review_quota_and_single_word_cadence_fill_requested_count_without_duplicates(): void
    {
        [$u, $c, $known] = $this->fixture(5);
        for ($i = 0; $i < 2; $i++) {
            $s = $this->start($c);
            $this->finish($c, $s, $this->twice($s))->assertOk();
        }
        $new = collect();
        for ($i = 0; $i < 10; $i++) {
            $new->push($u->words()->create(['term' => 'New '.$i, 'definition' => 'Unseen '.$i]));
        }
        $c->words()->attach($new->pluck('id'));
        $s = $this->start($c, ['count' => 10]);
        $ids = collect($s['questions'])->pluck('id');
        $this->assertCount(10, $ids);
        $this->assertCount(10, $ids->unique());
        $this->assertCount(2, $ids->intersect($known->pluck('id')));
        $this->assertCount(8, $ids->intersect($new->pluck('id')));
        // Only completed sessions count: the abandoned ten-word start above cannot move cadence.
        for ($i = 0; $i < 2; $i++) {
            $one = $this->start($c, ['count' => 1]);
            $this->assertNotSame('reinforced', $one['questions'][0]['prior_stage']);
            $this->finish($c, $one, $this->twice($one))->assertOk();
        }
        $fifth = $this->start($c, ['count' => 1]);
        $this->assertSame('reinforced', $fifth['questions'][0]['prior_stage']);
        $all = $this->start($c, ['count' => 2000]);
        $this->assertCount(15, $all['questions']);
    }

    public function test_legacy_and_incomplete_sessions_cannot_manufacture_mastery(): void
    {
        [$u, $c, $words] = $this->fixture();
        foreach ([['study', 3, true], ['test', 4, true], ['study', 4, false]] as [$mode,$version,$completed]) {
            $s = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $c->id, 'mode' => $mode, 'rules_version' => $version, 'settings' => [], 'questions' => [['id' => $words[0]->id, 'mastery_applied' => true, 'mastery_stage' => 'reinforced', 'mastery_qualified' => true]], 'total_count' => 1, 'correct_count' => 1, 'completed_at' => $completed ? now() : null]);
            $s->answers()->create(['word_id' => $words[0]->id, 'position' => 0, 'question_index' => 0, 'attempt_no' => 1, 'prompt' => 'prompt', 'expected' => 'answer', 'answer' => 'answer', 'correct' => true, 'self_assessed' => false]);
        }
        $next = $this->start($c);
        $this->assertSame('new', $next['questions'][0]['prior_stage']);
        $this->assertFalse($next['questions'][0]['prior_qualified']);
    }

    public function test_mastery_is_scoped_and_revoked_or_guest_sessions_cannot_persist(): void
    {
        [$owner, $c, $words] = $this->fixture();
        $s = $this->start($c);
        $this->finish($c, $s, $this->twice($s))->assertOk();
        $member = User::factory()->create();
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        $this->actingAs($member);
        $memberSession = $this->start($c);
        $this->assertSame('new', $memberSession['questions'][0]['prior_stage']);
        $this->actingAs($owner);
        $other = $owner->collections()->create(['title' => 'Other']);
        $other->words()->attach($words->pluck('id'));
        $this->assertSame('new', $this->start($other)['questions'][0]['prior_stage']);
        $this->finish($c, $memberSession, $this->twice($memberSession))->assertNotFound();
        $this->delete('/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->actingAs($member);
        $this->finish($c, $memberSession, $this->twice($memberSession))->assertNotFound();
        $this->post('/logout');
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study'])->assertUnauthorized();
    }

    public function test_removed_word_scores_snapshot_without_applying_mastery(): void
    {
        [, $c, $words] = $this->fixture();
        $s = $this->start($c);
        $c->words()->detach($words[0]);
        $this->finish($c, $s, $this->twice($s))->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('mastery.0.applied', false);
    }

    public function test_retry_type_and_option_validation_follow_snapshot_and_test_keeps_single_correct_completion(): void
    {
        [, $c] = $this->fixture(4);
        $s = $this->start($c, ['type' => 'choice', 'types' => ['choice', 'written'], 'directions' => ['term', 'definition']]);
        foreach ($s['questions'] as $q) {
            $this->assertSame('written', $q['retry_type']);
        }
        $response = $this->finish($c, $s, $this->twice($s))->assertOk()->json();
        foreach ($response['results'] as $r) {
            if ($r['attempt_no'] > 1) {
                $this->assertSame('written', $r['type']);
            }
        }
        $choice = $this->start($c, ['type' => 'choice', 'types' => ['choice']]);
        $bad = $this->twice($choice);
        $bad[0]['answer'] = 'Not an offered option';
        $this->finish($c, $choice, $bad)->assertUnprocessable();
        foreach ($choice['questions'] as $q) {
            $this->assertSame('choice', $q['retry_type']);
        }
        $test = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'test', 'settings' => ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
        $this->assertSame(5, $test['rules_version']);
        $this->finish($c, $test, [$this->answer($test['questions'][0])])->assertOk()->assertJsonPath('mastery', [])->assertJsonPath('session.total_attempts', 1);
    }

    public function test_finishing_already_open_legacy_session_does_not_create_v4_mastery(): void
    {
        [, $c] = $this->fixture();
        $legacy = $this->start($c);
        StudySession::findOrFail($legacy['id'])->update(['rules_version' => 3]);
        $this->finish($c, $legacy, [$this->answer($legacy['questions'][0])])->assertOk()->assertJsonPath('session.total_attempts', 1)->assertJsonPath('mastery', []);
        $next = $this->start($c);
        $this->assertSame('new', $next['questions'][0]['prior_stage']);
        $this->assertFalse($next['questions'][0]['prior_qualified']);
    }

    public function test_v4_self_assessment_is_limited_to_written_definition_and_score_stays_first_attempt(): void
    {
        [, $c] = $this->fixture();
        $terms = $this->start($c);
        $q = $terms['questions'][0];
        $this->finish($c, $terms, [$this->answer($q, 1, 'paraphrase') + ['self_assessed' => true], $this->answer($q, 2)])->assertUnprocessable();
        $defs = $this->start($c, ['direction' => 'definition']);
        $q = $defs['questions'][0];
        $this->finish($c, $defs, [$this->answer($q, 1, 'wrong'), $this->answer($q, 2, 'equivalent') + ['self_assessed' => true], $this->answer($q, 3)])->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('mastery.0.qualified', true)->assertJsonPath('mastery.0.stage', 'learning');
    }
}
