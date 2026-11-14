<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudySession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'questions' => 'array', 'practice_round' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function answers()
    {
        return $this->hasMany(StudyAnswer::class);
    }

    public function result(): array
    {
        return ['id' => $this->id, 'rules_version' => $this->rules_version, 'total_attempts' => $this->total_attempts ?? $this->total_count, 'repeat_count' => max(0, ($this->total_attempts ?? $this->total_count) - $this->total_count), 'mode' => $this->mode, 'practice_round' => $this->practice_round, 'completed_at' => $this->completed_at?->toIso8601String(), 'total' => $this->total_count, 'correct' => $this->correct_count, 'incorrect' => $this->total_count - ($this->correct_count ?? 0), 'percent' => $this->total_count ? (int) round(($this->correct_count ?? 0) / $this->total_count * 100) : 0, 'self_assessed' => $this->self_assessed_count];
    }

    public static function summaryFor(Collection $collection, ?int $learnerId = null): array
    {
        $query = self::where('collection_id', $collection->id)->where('user_id', $learnerId ?? $collection->user_id)->whereNotNull('completed_at');
        $modes = [];
        foreach (['flashcards', 'study', 'test'] as $mode) {
            $q = (clone $query)->where('mode', $mode);
            $last = (clone $q)->orderByDesc('completed_at')->orderByDesc('id')->first();
            $modes[$mode] = ['count' => $q->count(), 'total_practiced' => (int) (clone $q)->sum('total_count'), 'total_correct' => (int) (clone $q)->sum('correct_count'), 'last' => $last?->result(), 'best_percent' => $mode === 'test' ? (clone $q)->selectRaw('MAX(100.0 * correct_count / total_count) as best')->value('best') : null];
        }

        return ['total_sessions' => $query->count(), 'modes' => $modes, 'recent' => (clone $query)->orderByDesc('completed_at')->orderByDesc('id')->limit(10)->get()->map(fn ($s) => $s->result())->all()];
    }
}
