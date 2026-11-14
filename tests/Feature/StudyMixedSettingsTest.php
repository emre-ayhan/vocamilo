<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyMixedSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count = 9): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Mixed']);
        for ($i = 0; $i < $count; $i++) {
            $w = $u->words()->create(['term' => 'term'.$i, 'definition' => 'definition'.$i]);
            $c->words()->attach($w);
        }
        $this->actingAs($u);

        return [$u, $c];
    }

    private function start($c, array $settings = [], string $mode = 'study')
    {
        return $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => $mode, 'settings' => array_replace(['type' => 'choice', 'direction' => 'term', 'order' => 'ordered', 'count' => 9], $settings)]);
    }

    public function test_mixed_study_covers_balanced_combinations_without_duplicate_words(): void
    {
        [, $c] = $this->fixture();
        $settings = ['types' => ['choice', 'written'], 'directions' => ['term', 'definition']];
        $s = $this->start($c, $settings)->assertCreated()->json();
        $q = collect($s['questions']);
        $counts = $q->countBy(fn ($q) => $q['answerKey'].':'.$q['type']);
        $this->assertCount(4, $counts);
        $this->assertSame(1, $counts->max() - $counts->min());
        $this->assertCount(9, $q->pluck('id')->unique());
        foreach ($q as $item) {
            $w = $c->words()->findOrFail($item['id']);
            $this->assertSame($w->{$item['answerKey']}, $item['expected']);
            $this->assertSame($w->{$item['answerKey'] === 'term' ? 'definition' : 'term'}, $item['prompt']);
        }
        $this->assertSame($settings['types'], StudySession::findOrFail($s['id'])->settings['types']);
        $answers = $q->map(fn ($q) => ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => $q['expected']])->all();
        $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $answers])->assertOk()->assertJsonPath('session.correct', 9);
    }

    public function test_single_arrays_override_scalar_and_non_study_ignores_arrays(): void
    {
        [, $c] = $this->fixture(3);
        $settings = ['types' => ['written'], 'directions' => ['definition']];
        $s = $this->start($c, $settings)->assertCreated()->json();
        foreach ($s['questions'] as $q) {
            $this->assertSame('written', $q['type']);
            $this->assertSame('definition', $q['answerKey']);
        }
        foreach (['test', 'flashcards'] as $mode) {
            $s = $this->start($c, $settings, $mode)->assertCreated()->json();
            foreach ($s['questions'] as $q) {
                $this->assertSame('choice', $q['type']);
                $this->assertSame('term', $q['answerKey']);
            }
        }
    }

    public function test_invalid_empty_duplicate_or_unknown_variants_are_rejected(): void
    {
        [, $c] = $this->fixture(1);
        foreach ([['types' => []], ['directions' => []], ['types' => ['choice', 'choice']], ['directions' => ['term', 'term']], ['types' => ['essay']], ['directions' => ['other']], ['types' => 'written'], ['directions' => null]] as $settings) {
            $this->start($c, $settings)->assertUnprocessable();
        }
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_choice_falls_back_to_written_when_no_distinct_distractor(): void
    {
        [, $c] = $this->fixture(1);
        $s = $this->start($c, ['types' => ['choice'], 'directions' => ['definition']])->assertCreated()->json();
        $this->assertSame('written', $s['questions'][0]['type']);
    }

    public function test_unknown_priority_survives_mixed_assignment(): void
    {
        [, $c] = $this->fixture(3);
        $old = $this->start($c)->assertCreated()->json();
        $answers = array_map(fn ($q) => ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => $q['expected']], $old['questions']);
        $this->postJson('/collections/'.$c->id.'/sessions/'.$old['id'].'/complete', ['answers' => $answers])->assertOk();
        $u = $c->user;
        $new = $u->words()->create(['term' => 'new', 'definition' => 'new definition']);
        $c->words()->attach($new);
        $s = $this->start($c, ['types' => ['choice', 'written'], 'directions' => ['term', 'definition'], 'count' => 1])->assertCreated()->json();
        $this->assertSame($new->id,$s['questions'][0]['id']);
    }
}
