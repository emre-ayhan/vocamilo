<?php

namespace App\Http\Controllers;

use App\Models\StudySession;
use App\Models\Word;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudySessionController extends Controller
{
    public static function normalize(?string $value): string
    {
        $value = \Normalizer::normalize($value ?? '', \Normalizer::FORM_KC);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    public function store(Request $r, int $collection)
    {
        $c = $r->user()->collections()->findOrFail($collection);
        $d = $r->validate([
            'mode' => 'required|in:flashcards,study,test',
            'settings' => 'required|array:type,direction,order,count',
            'settings.type' => 'required|in:choice,written',
            'settings.direction' => 'required|in:term,definition',
            'settings.order' => 'required|in:ordered,random',
            'settings.count' => 'required|integer|min:1|max:2000',
            'word_ids' => 'sometimes|array|min:1|max:2000',
            'word_ids.*' => 'required|integer|distinct',
            'practice_round' => 'sometimes|boolean',
        ]);
        $words = $c->words()->get();
        if (isset($d['word_ids'])) {
            $words = $words->whereIn('id', $d['word_ids'])->values();
            abort_if($words->count() !== count($d['word_ids']), 422, 'Choose words from this collection.');
        }
        abort_if($words->isEmpty(), 422, 'Add words before starting a session.');
        $settings = $d['settings'];
        $answerKey = $settings['direction'] === 'definition' ? 'definition' : 'term';
        $promptKey = $answerKey === 'term' ? 'definition' : 'term';
        $ordered = $settings['order'] === 'random' ? $words->shuffle() : $words;
        $questions = $ordered->take($settings['count'])->map(function ($w) use ($words, $settings, $answerKey, $promptKey) {
            $others = $words->pluck($answerKey)->unique(fn ($v) => self::normalize($v))->filter(fn ($v) => self::normalize($v) !== self::normalize($w->$answerKey))->shuffle()->take(3);

            return ['id' => $w->id, 'prompt' => $w->$promptKey, 'expected' => $w->$answerKey, 'answerKey' => $answerKey, 'type' => $settings['type'] === 'choice' && $others->isNotEmpty() ? 'choice' : 'written', 'options' => $others->push($w->$answerKey)->shuffle()->values()->all()];
        })->values()->all();
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'collection_id' => $c->id, 'mode' => $d['mode'], 'settings' => $settings, 'practice_round' => $d['practice_round'] ?? false, 'questions' => $questions, 'total_count' => count($questions)]);
        // Notes may be displayed privately, but are not copied into historical data.
        $display = array_map(fn ($q) => $q + ['note' => $words->firstWhere('id', $q['id'])?->note], $questions);

        return response()->json(['id' => $session->id, 'questions' => $display], 201);
    }

    public function complete(Request $r, int $collection, string $session)
    {
        $c = $r->user()->collections()->findOrFail($collection);

        return DB::transaction(function () use ($r, $c, $session) {
            $s = StudySession::whereKey($session)->where('collection_id', $c->id)->where('user_id', $r->user()->id)->lockForUpdate()->firstOrFail();
            if ($s->completed_at) {
                return response()->json($this->completed($s));
            }
            $d = $r->validate(['answers' => 'required|array|size:'.$s->total_count, 'answers.*' => 'required|array:word_id,answer,known,self_assessed', 'answers.*.word_id' => 'required|integer|distinct', 'answers.*.answer' => 'nullable|string|max:5000', 'answers.*.known' => 'sometimes|boolean', 'answers.*.self_assessed' => 'sometimes|boolean']);
            $byId = collect($d['answers'])->keyBy('word_id');
            $correct = 0;
            $assessed = 0;
            $existingIds = Word::where('user_id', $r->user()->id)->whereIn('id', array_column($s->questions, 'id'))->pluck('id')->all();
            foreach ($s->questions as $i => $q) {
                $a = $byId->get($q['id']);
                if (! $a) {
                    throw ValidationException::withMessages(['answers' => 'Answers must match every question in this session.']);
                }
                $self = (bool) ($a['self_assessed'] ?? false);
                if ($self && ! ($s->mode === 'study' && $q['type'] === 'written' && $q['answerKey'] === 'definition')) {
                    throw ValidationException::withMessages(['answers' => 'Self assessment is allowed only for written definitions in Study.']);
                }
                if ($s->mode === 'flashcards') {
                    if (! array_key_exists('known', $a)) {
                        throw ValidationException::withMessages(['answers' => 'Mark each flashcard known or still learning.']);
                    }
                    $isCorrect = (bool) $a['known'];
                    $self = true;
                } else {
                    if (isset($a['known'])) {
                        throw ValidationException::withMessages(['answers' => 'Known marks are only allowed for flashcards.']);
                    }
                    if (! filled($a['answer'] ?? null)) {
                        throw ValidationException::withMessages(['answers' => 'Answer every question.']);
                    }
                    if ($q['type'] === 'choice' && ! in_array($a['answer'], $q['options'], true)) {
                        throw ValidationException::withMessages(['answers' => 'Choose one of the offered answers.']);
                    }
                    $isCorrect = self::normalize($a['answer']) === self::normalize($q['expected']) || $self;
                }
                $correct += (int) $isCorrect;
                $assessed += (int) $self;
                $s->answers()->create(['word_id' => in_array($q['id'], $existingIds, true) ? $q['id'] : null, 'position' => $i, 'prompt' => $q['prompt'], 'expected' => $q['expected'], 'answer' => $a['answer'] ?? null, 'correct' => $isCorrect, 'self_assessed' => $self]);
            }
            $s->update(['correct_count' => $correct, 'self_assessed_count' => $assessed, 'completed_at' => now()]);

            return response()->json($this->completed($s));
        });
    }

    private function completed(StudySession $s): array
    {
        $answers = $s->answers()->orderBy('position')->get();

        return ['session' => $s->result(), 'results' => $answers->map(function ($a) use ($s) {
            return $s->questions[$a->position] + ['answer' => $a->answer ?? '', 'correct' => $a->correct, 'self_assessed' => $a->self_assessed];
        })->all()];
    }
}
