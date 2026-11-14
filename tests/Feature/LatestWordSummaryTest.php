<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LatestWordSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Latest']);
        $words = [];
        for ($i = 0; $i < 4; $i++) {
            $w = $u->words()->create(['term' => 'word'.$i, 'definition' => 'meaning'.$i]);
            $c->words()->attach($w);
            $words[] = $w;
        }

        return [$u, $c, $words];
    }

    private function record($u, $c, $w, bool $correct, string $mode = 'study', int $minutesAgo = 0, int $attempt = 1, bool $complete = true): void
    {
        $s = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $c->id, 'mode' => $mode, 'settings' => [], 'questions' => [], 'total_count' => 1, 'correct_count' => (int) $correct, 'completed_at' => $complete ? now()->subMinutes($minutesAgo) : null]);
        $s->answers()->create(['word_id' => $w->id, 'position' => 0, 'attempt_no' => $attempt, 'prompt' => $w->definition, 'expected' => $w->term, 'answer' => $correct ? $w->term : 'wrong', 'correct' => $correct]);
    }

    public function test_latest_first_attempt_replaces_old_result_and_ignores_retries_but_counts_unseen_words(): void
    {
        [$u,$c,$w] = $this->fixture();
        $this->record($u, $c, $w[0], false, 'study', 5);
        $this->record($u, $c, $w[0], true, 'study', 1);
        $this->record($u, $c, $w[1], true, 'study', 5);
        $this->record($u, $c, $w[1], false, 'study', 1);
        $this->record($u, $c, $w[1], true, 'study', 0, 2);
        $m = StudySession::summaryFor($c)['modes']['study'];
        $this->assertSame(2, $m['latest_practiced']);
        $this->assertSame(1, $m['latest_correct']);
        $this->assertSame(25, $m['latest_percent']);
    }

    public function test_learner_collection_mode_and_completed_scope_are_independent(): void
    {
        [$u,$c,$w] = $this->fixture();
        $member = User::factory()->create();
        $other = $u->collections()->create(['title' => 'Other']);
        $other->words()->attach($w[0]);
        $this->record($u, $c, $w[0], false);
        $this->record($member, $c, $w[0], true);
        $this->record($u, $other, $w[0], true);
        $this->record($u, $c, $w[0], true, 'study', 0, 1, false);
        $this->record($u, $c, $w[0], true, 'test');
        $this->record($u, $c, $w[0], true, 'flashcards', 5);
        $this->record($u, $c, $w[0], false, 'flashcards');
        $summary = StudySession::summaryFor($c);
        $this->assertSame(0, $summary['modes']['study']['latest_correct']);
        $this->assertSame(1, $summary['modes']['test']['latest_correct']);
        $this->assertSame(0, $summary['modes']['flashcards']['latest_correct']);
        $this->assertSame(1, StudySession::summaryFor($c, $member->id)['modes']['study']['latest_correct']);
    }

    public function test_deleted_and_detached_words_leave_denominator_but_unrecorded_words_remain(): void
    {
        [$u,$c,$w] = $this->fixture();
        foreach (array_slice($w, 0, 3) as $word) {
            $this->record($u, $c, $word, true);
        }
        $c->words()->detach($w[0]);
        $w[1]->delete();
        $m = StudySession::summaryFor($c)['modes']['study'];
        $this->assertSame(1, $m['latest_practiced']);
        $this->assertSame(50, $m['latest_percent']);
        $c->words()->detach($w[2]);
        $m = StudySession::summaryFor($c)['modes']['study'];
        $this->assertSame(0, $m['latest_practiced']);
        $this->assertSame(0, $m['latest_correct']);
        $this->assertSame(0, $m['latest_percent']);
    }

    public function test_legacy_defaults_and_equal_timestamp_answer_id_tiebreak_without_inventing_answers(): void
    {
        [$u,$c,$w] = $this->fixture();
        $this->record($u, $c, $w[0], false);
        $this->record($u, $c, $w[0], true);
        $s = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $c->id, 'mode' => 'study', 'settings' => [], 'questions' => [], 'total_count' => 20, 'correct_count' => 20, 'completed_at' => now()]);
        // Legacy answer omits both attempt_no and question_index, using migration defaults.
        $s->answers()->create(['word_id' => $w[1]->id, 'position' => 0, 'prompt' => 'meaning', 'expected' => 'word', 'answer' => 'wrong', 'correct' => false]);
        $m = StudySession::summaryFor($c)['modes']['study'];
        $this->assertSame(2, $m['latest_practiced']);
        $this->assertSame(1, $m['latest_correct']);
        $this->assertSame(25, $m['latest_percent']);
        $this->assertSame(0, StudySession::summaryFor($c)['modes']['test']['latest_practiced']);
    }

    public function test_collection_size_changes_denominator_and_empty_collection_has_no_percentage(): void
    {
        [$u, $c, $words] = $this->fixture();
        foreach (['study', 'test', 'flashcards'] as $mode) {
            $this->record($u, $c, $words[0], true, $mode);
        }
        foreach (StudySession::summaryFor($c)['modes'] as $mode) {
            $this->assertSame(4, $mode['collection_word_count']);
            $this->assertSame(25, $mode['latest_percent']);
        }
        $new = $u->words()->create(['term' => 'unseen', 'definition' => 'new']);
        $c->words()->attach($new);
        foreach (StudySession::summaryFor($c)['modes'] as $mode) {
            $this->assertSame(5, $mode['collection_word_count']);
            $this->assertSame(20, $mode['latest_percent']);
            $this->assertSame(1, $mode['latest_practiced']);
        }
        $member = User::factory()->create();
        foreach (StudySession::summaryFor($c, $member->id)['modes'] as $mode) {
            $this->assertSame(5, $mode['collection_word_count']);
            $this->assertSame(0, $mode['latest_percent']);
        }
        $c->words()->detach($new);
        foreach (StudySession::summaryFor($c)['modes'] as $mode) {
            $this->assertSame(25, $mode['latest_percent']);
        }
        $c->words()->detach();
        foreach (StudySession::summaryFor($c)['modes'] as $mode) {
            $this->assertSame(0, $mode['collection_word_count']);
            $this->assertSame(0, $mode['latest_correct']);
            $this->assertNull($mode['latest_percent']);
        }
    }
}
