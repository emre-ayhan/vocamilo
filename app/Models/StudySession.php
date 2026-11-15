<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StudySession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['skipped_count' => 'integer', 'rules_version' => 'integer', 'settings' => 'array', 'questions' => 'array', 'practice_round' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function answers()
    {
        return $this->hasMany(StudyAnswer::class);
    }

    public function result(): array
    {
        return ['id' => $this->id, 'rules_version' => $this->rules_version, 'total_attempts' => $this->total_attempts ?? $this->total_count, 'repeat_count' => max(0, ($this->total_attempts ?? $this->total_count) - $this->total_count), 'mode' => $this->mode, 'practice_round' => $this->practice_round, 'completed_at' => $this->completed_at?->toIso8601String(), 'total' => $this->total_count, 'correct' => $this->correct_count, 'skipped' => $this->skipped_count ?? 0, 'incorrect' => $this->total_count - ($this->correct_count ?? 0) - ($this->skipped_count ?? 0), 'percent' => $this->total_count ? (int) round(($this->correct_count ?? 0) / $this->total_count * 100) : 0, 'self_assessed' => $this->self_assessed_count];
    }

    public static function summaryFor(Collection $collection, ?int $learnerId = null): array
    {
        $query = self::where('collection_id', $collection->id)->where('user_id', $learnerId ?? $collection->user_id)->whereNotNull('completed_at');
        $collectionWordCount = $collection->words()->count();
        $modes = [];
        foreach (['flashcards', 'study', 'test'] as $mode) {
            $q = (clone $query)->where('mode', $mode);
            $last = (clone $q)->orderByDesc('completed_at')->orderByDesc('id')->first();
            // Only recorded first attempts for words still in this collection contribute.
            // Older rows default to attempt_no=1; never infer missing answers from aggregate scores.
            $latestAnswers = DB::table('study_answers as a')
                ->join('study_sessions as s', 's.id', '=', 'a.study_session_id')
                ->join('collection_word as cw', function ($join) use ($collection) {
                    $join->on('cw.word_id', '=', 'a.word_id')->where('cw.collection_id', $collection->id);
                })
                ->where('s.collection_id', $collection->id)
                ->where('s.user_id', $learnerId ?? $collection->user_id)
                ->where('s.mode', $mode)->whereNotNull('s.completed_at')
                ->where('a.attempt_no', 1)
                ->orderByDesc('s.completed_at')->orderByDesc('a.id')
                ->get(['a.word_id', 'a.correct'])->unique('word_id');
            $latestPracticed = $latestAnswers->count();
            $latestCorrect = $latestAnswers->filter(fn ($answer) => (bool) $answer->correct)->count();
            $modes[$mode] = ['latest_practiced' => $latestPracticed, 'latest_correct' => $latestCorrect, 'collection_word_count' => $collectionWordCount, 'latest_percent' => $collectionWordCount ? (int) round($latestCorrect / $collectionWordCount * 100) : null, 'count' => $q->count(), 'total_practiced' => (int) (clone $q)->sum('total_count'), 'total_correct' => (int) (clone $q)->sum('correct_count'), 'last' => $last?->result(), 'best_percent' => $mode === 'test' ? (clone $q)->selectRaw('MAX(100.0 * correct_count / total_count) as best')->value('best') : null];
        }

        return ['total_sessions' => $query->count(), 'modes' => $modes, 'recent' => (clone $query)->orderByDesc('completed_at')->orderByDesc('id')->limit(10)->get()->map(fn ($s) => $s->result())->all()];
    }
}
