<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyAnswerVariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_types_and_two_directions_have_balanced_snapshot_combinations(): void
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Six combinations']);
        for ($i = 0; $i < 12; $i++) {
            $c->words()->attach($u->words()->create(['term' => 'word'.$i, 'definition' => 'meaning'.$i]));
        }
        $this->actingAs($u);
        foreach (['study', 'test'] as $mode) {
            $s = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => $mode, 'settings' => ['type' => 'rearrange', 'types' => ['choice', 'written', 'rearrange'], 'direction' => 'term', 'directions' => ['term', 'definition'], 'order' => 'ordered', 'count' => 12]])->assertCreated()->assertJsonPath('rules_version', $mode === 'study' ? 6 : 5)->json();
            $questions = collect($s['questions']);
            $this->assertCount(12, $questions->pluck('id')->unique());
            $counts = $questions->countBy(fn ($q) => $q['type'].':'.$q['answerKey']);
            $this->assertCount(6, $counts);
            $this->assertSame([2], $counts->unique()->values()->all());
            if ($mode === 'study') {
                $this->assertSame(['written'], $questions->pluck('retry_type')->unique()->values()->all());
            }
        }
        $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'flashcards', 'settings' => ['type' => 'rearrange', 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertUnprocessable();
    }

    public function test_feedback_migration_preserves_existing_results_and_defaults_flags_without_reclassification(): void
    {
        $migration = require database_path('migrations/2026_11_15_000009_add_attempt_feedback.php');
        $migration->down();
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Old results']);
        $w = $u->words()->create(['term' => 'old', 'definition' => 'meaning']);
        $c->words()->attach($w);
        $s = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'collection_id' => $c->id, 'mode' => 'test', 'rules_version' => 3, 'settings' => [], 'questions' => [['id' => $w->id]], 'total_count' => 1, 'correct_count' => 0, 'completed_at' => now()]);
        $s->answers()->create(['word_id' => $w->id, 'position' => 0, 'prompt' => 'meaning', 'expected' => 'old', 'answer' => 'wrong', 'correct' => false]);
        $before = [];
        foreach (['study_sessions', 'study_answers'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $migration->up();
        foreach (['study_sessions', 'study_answers'] as $table) {
            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                if ($table === 'study_sessions') {
                    $this->assertSame(0, $row->skipped_count);
                    unset($row->skipped_count);
                } else {
                    $this->assertSame(0, $row->had_error);
                    $this->assertSame(0, $row->skipped);
                    unset($row->had_error, $row->skipped);
                }
            }
            $this->assertSame($before[$table], $rows->toJson());
        }
        $this->assertSame(1, $s->fresh()->result()['incorrect']);
        $this->assertSame(0, $s->fresh()->result()['skipped']);
    }
}
