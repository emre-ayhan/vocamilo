<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyDirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_modes_snapshot_question_and_choice_direction_without_changing_answer_scoring(): void
    {
        $owner = User::factory()->create();
        $collection = $owner->collections()->create(['title' => 'Mixed scripts']);
        $words = collect();
        foreach ([['كتاب', 'A book', 'rtl'], ['sun', 'الشمس', 'ltr'], ['123', '456', 'rtl']] as [$term, $definition, $direction]) {
            $word = $owner->words()->create(['term' => $term, 'definition' => $definition]);
            $word->forceFill(['text_direction' => $direction])->save();
            $collection->words()->attach($word);
            $words->push($word);
        }
        $this->actingAs($owner);
        foreach (['flashcards', 'study', 'test'] as $mode) {
            $response = $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => $mode, 'settings' => ['type' => 'choice', 'direction' => 'definition', 'order' => 'ordered', 'count' => 3]])->assertCreated();
            $questions = $response->json('questions');
            foreach ($questions as $question) {
                $word = $words->firstWhere('id', $question['id']);
                $this->assertSame($word->text_direction, $question['text_direction']);
                $this->assertCount(count($question['options']), $question['option_directions']);
                foreach ($question['options'] as $i => $option) {
                    $this->assertSame($words->firstWhere('definition', $option)->text_direction, $question['option_directions'][$i]);
                }
            }
            $session = StudySession::findOrFail($response->json('id'));
            $this->assertSame($questions[0]['text_direction'], $session->questions[0]['text_direction']);
            $answers = array_map(fn ($q) => $mode === 'flashcards' ? ['word_id' => $q['id'], 'known' => true] : ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => $q['expected']], $questions);
            $done = $this->postJson('/collections/'.$collection->id.'/sessions/'.$session->id.'/complete', ['answers' => $answers])->assertOk()->assertJsonPath('session.correct', 3);
            $this->assertSame($questions[0]['text_direction'], $done->json('results.0.text_direction'));
            $this->assertSame($questions[0]['option_directions'], $done->json('results.0.option_directions'));
        }
    }
}
