<?php

namespace App\Http\Controllers;

use App\Models\Collection;
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
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $d = $r->validate([
            'mode' => 'required|in:flashcards,study,test',
            'settings' => 'required|array:type,direction,types,directions,order,count',
            'settings.type' => 'required|in:choice,written',
            'settings.direction' => 'required|in:term,definition',
            'settings.directions' => 'sometimes|array|min:1|max:2',
            'settings.directions.*' => 'required|distinct|in:term,definition',
            'settings.types' => 'sometimes|array|min:1|max:2',
            'settings.types.*' => 'required|distinct|in:choice,written',
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
        $ordered = $settings['order'] === 'random' ? $words->shuffle() : $words;
        if ($d['mode'] === 'study') {
            $history = DB::table('study_answers as a')->join('study_sessions as s', 's.id', '=', 'a.study_session_id')->where('s.collection_id', $c->id)->where('s.user_id', $r->user()->id)->where('s.mode', 'study')->whereNotNull('s.completed_at')->where('a.attempt_no', 1)->whereNotNull('a.word_id')->orderByDesc('s.completed_at')->orderByDesc('a.id')->get(['a.word_id', 'a.correct']);
            $known = [];
            foreach ($history as $row) {
                if (! array_key_exists($row->word_id, $known)) {
                    $known[$row->word_id] = (bool) $row->correct;
                }
            }
            $ordered = $ordered->filter(fn ($w) => ! ($known[$w->id] ?? false))->concat($ordered->filter(fn ($w) => $known[$w->id] ?? false))->values();
        }
        $directions = in_array($d['mode'], ['study', 'test'], true) ? ($settings['directions'] ?? [$settings['direction']]) : [$settings['direction']];
        $types = in_array($d['mode'], ['study', 'test'], true) ? ($settings['types'] ?? [$settings['type']]) : [$settings['type']];
        $combinations = [];
        foreach ($directions as $direction) {
            foreach ($types as $type) {
                $combinations[] = ['direction' => $direction, 'type' => $type];
            }
        }
        $cycle = collect();
        $questions = $ordered->take($settings['count'])->values()->map(function ($w, $index) use ($words, $combinations, &$cycle) {
            // Shuffle each complete cycle: selected combinations differ in frequency by at most one.
            if ($index % count($combinations) === 0) {
                $cycle = collect($combinations)->shuffle();
            }
            $variant = $cycle[$index % count($combinations)];
            $answerKey = $variant['direction'];
            $promptKey = $answerKey === 'term' ? 'definition' : 'term';
            $others = $words->pluck($answerKey)->unique(fn ($v) => self::normalize($v))->filter(fn ($v) => self::normalize($v) !== self::normalize($w->$answerKey))->shuffle()->take(3);

            return ['id' => $w->id, 'prompt' => $w->$promptKey, 'expected' => $w->$answerKey, 'answerKey' => $answerKey, 'type' => $variant['type'] === 'choice' && $others->isNotEmpty() ? 'choice' : 'written', 'options' => $others->push($w->$answerKey)->shuffle()->values()->all()];
        })->values()->all();
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'collection_id' => $c->id, 'mode' => $d['mode'], 'settings' => $settings, 'practice_round' => $d['practice_round'] ?? false, 'questions' => $questions, 'total_count' => count($questions), 'rules_version' => in_array($d['mode'], ['study', 'test'], true) ? 3 : 1]);
        // Notes may be displayed privately, but are not copied into historical data.
        $notes = $c->user_id === $r->user()->id ? $words->pluck('note', 'id') : DB::table('user_word_notes')->where('user_id', $r->user()->id)->pluck('note', 'word_id');
        $display = array_map(fn ($q) => $q + ['note' => $notes[$q['id']] ?? null], $questions);

        return response()->json(['id' => $session->id, 'questions' => $display, 'rules_version' => $session->rules_version], 201);
    }

    public function complete(Request $r, int $collection, string $session)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);

        return DB::transaction(function () use ($r, $c, $session) {
            $s = StudySession::whereKey($session)->where('collection_id', $c->id)->where('user_id', $r->user()->id)->lockForUpdate()->firstOrFail();
            if ($s->completed_at) {
                return response()->json($this->completed($s));
            }
            if (($s->mode === 'study' && $s->rules_version >= 2) || ($s->mode === 'test' && $s->rules_version >= 3)) {
                return $this->completeStudy($r, $s);
            }
            $d = $r->validate(['answers' => 'required|array|size:'.$s->total_count, 'answers.*' => 'required|array:word_id,answer,known,self_assessed', 'answers.*.word_id' => 'required|integer|distinct', 'answers.*.answer' => 'nullable|string|max:5000', 'answers.*.known' => 'sometimes|boolean', 'answers.*.self_assessed' => 'sometimes|boolean']);
            $byId = collect($d['answers'])->keyBy('word_id');
            $correct = 0;
            $assessed = 0;
            $existingIds = Word::whereIn('id', array_column($s->questions, 'id'))->pluck('id')->all();
            foreach ($s->questions as $i => $q) {
                $a = $byId->get($q['id']);
                if (! $a) {
                    throw ValidationException::withMessages(['answers' => __('Answers must match every question in this session.')]);
                }
                $self = (bool) ($a['self_assessed'] ?? false);
                if ($self && ! ($s->mode === 'study' && $q['type'] === 'written' && $q['answerKey'] === 'definition')) {
                    throw ValidationException::withMessages(['answers' => __('Self assessment is allowed only for written definitions in Study.')]);
                }
                if ($s->mode === 'flashcards') {
                    if (! array_key_exists('known', $a)) {
                        throw ValidationException::withMessages(['answers' => __('Mark each flashcard known or still learning.')]);
                    }
                    $isCorrect = (bool) $a['known'];
                    $self = true;
                } else {
                    if (isset($a['known'])) {
                        throw ValidationException::withMessages(['answers' => __('Known marks are only allowed for flashcards.')]);
                    }
                    if (! filled($a['answer'] ?? null)) {
                        throw ValidationException::withMessages(['answers' => __('Answer every question.')]);
                    }
                    if ($q['type'] === 'choice' && ! in_array($a['answer'], $q['options'], true)) {
                        throw ValidationException::withMessages(['answers' => __('Choose one of the offered answers.')]);
                    }
                    $isCorrect = self::normalize($a['answer']) === self::normalize($q['expected']) || $self;
                }
                $correct += (int) $isCorrect;
                $assessed += (int) $self;
                $s->answers()->create(['word_id' => in_array($q['id'], $existingIds, true) ? $q['id'] : null, 'position' => $i, 'prompt' => $q['prompt'], 'expected' => $q['expected'], 'answer' => $a['answer'] ?? null, 'correct' => $isCorrect, 'self_assessed' => $self]);
            }
            $s->update(['correct_count' => $correct, 'self_assessed_count' => $assessed, 'completed_at' => now(), 'total_attempts' => $s->total_count]);

            return response()->json($this->completed($s));
        });
    }

    private function completeStudy(Request $r, StudySession $s)
    {
        $d = $r->validate(['answers' => 'required|array|min:1|max:20000', 'answers.*' => 'required|array:word_id,attempt_no,answer,self_assessed', 'answers.*.word_id' => 'required|integer', 'answers.*.attempt_no' => 'required|integer|min:1', 'answers.*.answer' => 'required|string|max:5000', 'answers.*.self_assessed' => 'sometimes|boolean']);
        $queue = array_keys($s->questions);
        $attempts = [];
        $correct = 0;
        $assessed = 0;
        $existing = Word::whereIn('id', array_column($s->questions, 'id'))->pluck('id')->all();
        foreach ($d['answers'] as $position => $a) {
            if (! isset($queue[$position])) {
                throw ValidationException::withMessages(['answers' => __('Unexpected extra attempt.')]);
            }
            $qi = $queue[$position];
            $q = $s->questions[$qi];
            $number = ($attempts[$qi] ?? 0) + 1;
            if ($a['word_id'] != $q['id'] || $a['attempt_no'] != $number) {
                throw ValidationException::withMessages(['answers' => __('Follow the session question and retry order.')]);
            }
            $attempts[$qi] = $number;
            $self = (bool) ($a['self_assessed'] ?? false);
            if ($self && ! ($s->mode === 'study' && $q['type'] === 'written' && $q['answerKey'] === 'definition')) {
                throw ValidationException::withMessages(['answers' => __('Self assessment is allowed only for written definitions in Study.')]);
            }
            if (! filled(trim($a['answer']))) {
                throw ValidationException::withMessages(['answers' => __('Answer every question.')]);
            }
            if ($q['type'] === 'choice' && ! in_array($a['answer'], $q['options'], true)) {
                throw ValidationException::withMessages(['answers' => __('Choose one of the offered answers.')]);
            }
            $right = self::normalize($a['answer']) === self::normalize($q['expected']) || $self;
            if ($number === 1) {
                $correct += (int) $right;
                $assessed += (int) $self;
            }
            // v3 caps practice at two extra tries, stopping as soon as an answer is correct.
            // Keep v2 validation for sessions opened before this rollout.
            $retry = $s->rules_version >= 3
                ? (! $right && $number < 3)
                : (($number === 1 && ! $right) || $number === 2 || ($number >= 3 && ! $right));
            if ($retry) {
                $queue[] = $qi;
            }
            $s->answers()->create(['word_id' => in_array($q['id'], $existing, true) ? $q['id'] : null, 'position' => $position, 'question_index' => $qi, 'attempt_no' => $number, 'prompt' => $q['prompt'], 'expected' => $q['expected'], 'answer' => $a['answer'], 'correct' => $right, 'self_assessed' => $self]);
        }
        if (count($d['answers']) !== count($queue)) {
            throw ValidationException::withMessages(['answers' => __('Finish all questions and required retries before saving.')]);
        }
        $s->update(['correct_count' => $correct, 'self_assessed_count' => $assessed, 'completed_at' => now(), 'total_attempts' => count($d['answers'])]);

        return response()->json($this->completed($s));
    }

    private function completed(StudySession $s): array
    {
        $answers = $s->answers()->orderBy('position')->get();

        $results = $answers->map(function ($a) use ($s) {
            return $s->questions[$a->question_index ?? $a->position] + ['attempt_no' => $a->attempt_no, 'first_attempt' => $a->attempt_no === 1, 'answer' => $a->answer ?? '', 'correct' => $a->correct, 'self_assessed' => $a->self_assessed];
        })->all();

        return ['session' => $s->result(), 'results' => $results, 'first_results' => array_values(array_filter($results, fn ($r) => $r['first_attempt']))];
    }
}
