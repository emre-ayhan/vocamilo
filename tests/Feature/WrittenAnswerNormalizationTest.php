<?php

namespace Tests\Feature;

use App\Http\Controllers\StudySessionController;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrittenAnswerNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unicode_normalization_is_locale_independent_and_preserves_meaningful_marks(): void
    {
        foreach ([['CAFÉ', "cafe\u{0301}"], ['ＦＯＯ', 'foo'], ["  two\u{00A0}\twords\n", 'two words'], ['İZMİR', "i\u{0307}zmi\u{0307}r"], ['I', 'i'], ['ﻛﺘﺎﺏ', 'كتاب']] as [$expected, $answer]) {
            $this->assertSame(StudySessionController::normalize($expected), StudySessionController::normalize($answer));
        }
        foreach ([['I', 'ı'], ['İ', 'i'], ['café', 'cafe'], ['كِتاب', 'كتاب'], ['ice-cream', 'ice cream']] as [$expected, $answer]) {
            $this->assertNotSame(StudySessionController::normalize($expected), StudySessionController::normalize($answer));
        }
    }

    public function test_written_box_answers_keep_server_snapshot_authority_and_legacy_protocols(): void
    {
        $user = User::factory()->create();
        $collection = $user->collections()->create(['title' => 'Unicode writing']);
        $word = $user->words()->create(['term' => 'CAFÉ', 'definition' => 'A drink', 'note' => 'PRIVATE NOTE']);
        $collection->words()->attach($word);
        $this->actingAs($user);
        foreach ([['study', 4], ['test', 3], ['study', 3]] as [$mode, $version]) {
            $word->update(['term' => 'CAFÉ']);
            $started = $this->postJson('/collections/'.$collection->id.'/sessions', ['mode' => $mode, 'settings' => ['type' => 'written', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->json();
            if ($version !== $started['rules_version']) {
                // A pre-rollout open session still uses its original validation protocol.
                StudySession::findOrFail($started['id'])->update(['rules_version' => $version]);
            }
            $word->update(['term' => 'Changed after start']);
            $answers = [['word_id' => $word->id, 'attempt_no' => 1, 'answer' => "cafe\u{0301}"]];
            if ($version === 4) {
                $answers[] = ['word_id' => $word->id, 'attempt_no' => 2, 'answer' => 'café'];
            }
            $this->postJson('/collections/'.$collection->id.'/sessions/'.$started['id'].'/complete', ['answers' => $answers, 'expected' => 'forged', 'correct' => 999, 'percent' => 999])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.percent', 100)->assertJsonPath('results.0.expected', 'CAFÉ');
            $saved = StudySession::findOrFail($started['id']);
            $this->assertArrayNotHasKey('note', $saved->questions[0]);
        }
    }
}
