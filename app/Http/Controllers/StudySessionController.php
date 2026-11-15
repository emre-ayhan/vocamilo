<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\StudySession;
use App\Models\Word;
use App\Support\StudyMastery;
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

        return DB::transaction(function () use ($r, $c) {
            Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();

            return $this->start($r, $c->id);
        });
    }

    private function start(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $d = $r->validate([
            'mode' => 'required|in:flashcards,study,test',
            'settings' => 'required|array:type,direction,types,directions,order,count',
            'settings.type' => 'required|in:choice,written,rearrange',
            'settings.direction' => 'required|in:term,definition',
            'settings.directions' => 'sometimes|array|min:1|max:2',
            'settings.directions.*' => 'required|distinct|in:term,definition',
            'settings.types' => 'sometimes|array|min:1|max:3',
            'settings.types.*' => 'required|distinct|in:choice,written,rearrange',
            'settings.order' => 'required|in:ordered,random',
            'settings.count' => 'required|integer|min:1|max:2000',
            'word_ids' => 'sometimes|array|min:1|max:2000',
            'word_ids.*' => 'required|integer|distinct',
            'practice_round' => 'sometimes|boolean',
        ]);
        abort_if($d['mode'] === 'flashcards' && $d['settings']['type'] === 'rearrange', 422);
        $words = $c->words()->get();
        if (isset($d['word_ids'])) {
            $words = $words->whereIn('id', $d['word_ids'])->values();
            abort_if($words->count() !== count($d['word_ids']), 422, 'Choose words from this collection.');
        }
        abort_if($words->isEmpty(), 422, 'Add words before starting a session.');
        $settings = $d['settings'];
        $ordered = $settings['order'] === 'random' ? $words->shuffle() : $words;
        $mastery = [];
        if ($d['mode'] === 'study') {
            $mastery = StudyMastery::forLearner($c->id, $r->user()->id);
            $count = min($settings['count'], $ordered->count());
            $review = $ordered->filter(fn ($w) => ($mastery[$w->id]['stage'] ?? 'new') === 'reinforced')->sortBy(fn ($w) => [$mastery[$w->id]['reviewed_at'], $mastery[$w->id]['sequence']])->values();
            $learning = $ordered->reject(fn ($w) => ($mastery[$w->id]['stage'] ?? 'new') === 'reinforced')->sortBy(fn ($w) => (int) ($mastery[$w->id]['qualified'] ?? false))->values();
            $number = StudySession::where('collection_id', $c->id)->where('user_id', $r->user()->id)->where('mode', 'study')->where('rules_version', '>=', 4)->whereNotNull('completed_at')->count() + 1;
            $quota = $count >= 2 ? max(1, (int) round($count * .2)) : ($number % 5 === 0 ? 1 : 0);
            $reserved = min($quota, $review->count());
            $selectedLearning = $learning->take($count - $reserved);
            $ordered = $selectedLearning->concat($review->take($count - $selectedLearning->count()))->values();
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
        $questions = $ordered->take($settings['count'])->values()->map(function ($w, $index) use ($words, $combinations, &$cycle, $mastery, $types, $d) {
            // Shuffle each complete cycle: selected combinations differ in frequency by at most one.
            if ($index % count($combinations) === 0) {
                $cycle = collect($combinations)->shuffle();
            }
            $variant = $cycle[$index % count($combinations)];
            $answerKey = $variant['direction'];
            $promptKey = $answerKey === 'term' ? 'definition' : 'term';
            $others = $words->unique(fn ($candidate) => self::normalize($candidate->$answerKey))->filter(fn ($candidate) => self::normalize($candidate->$answerKey) !== self::normalize($w->$answerKey))->shuffle()->take(3);
            $type = $variant['type'] === 'rearrange' ? 'rearrange' : ($variant['type'] === 'choice' && ($others->isNotEmpty() || $d['mode'] === 'study') ? 'choice' : 'written');
            $choices = $others->push($w)->shuffle()->values();

            // Snapshot presentation separately from scoring; each distractor may use another writing direction.
            $extra = $d['mode'] === 'study' ? ['prior_stage' => $mastery[$w->id]['stage'] ?? 'new', 'prior_qualified' => $mastery[$w->id]['qualified'] ?? false, 'prior_baseline' => $mastery[$w->id]['baseline'] ?? null, 'retry_type' => in_array('written', $types, true) ? 'written' : $type] : [];

            return $extra + ['id' => $w->id, 'text_direction' => $w->text_direction ?? 'ltr', 'prompt' => $w->$promptKey, 'expected' => $w->$answerKey, 'answerKey' => $answerKey, 'type' => $type, 'options' => $choices->map(fn ($candidate) => $candidate->$answerKey)->all(), 'option_directions' => $choices->map(fn ($candidate) => $candidate->text_direction ?? 'ltr')->all()];
        })->values()->all();
        $session = StudySession::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'collection_id' => $c->id, 'mode' => $d['mode'], 'settings' => $settings, 'practice_round' => $d['practice_round'] ?? false, 'questions' => $questions, 'total_count' => count($questions), 'rules_version' => $d['mode'] === 'study' ? 6 : ($d['mode'] === 'test' ? 5 : 1)]);
        // Notes may be displayed privately, but are not copied into historical data.
        $notes = $c->user_id === $r->user()->id ? $words->pluck('note', 'id') : DB::table('user_word_notes')->where('user_id', $r->user()->id)->pluck('note', 'word_id');
        $display = array_map(fn ($q) => $q + ['note' => $notes[$q['id']] ?? null], $questions);

        return response()->json(['id' => $session->id, 'questions' => $display, 'rules_version' => $session->rules_version], 201);
    }

    public function complete(Request $r, int $collection, string $session)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);

        return DB::transaction(function () use ($r, $c, $session) {
            Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            Collection::accessibleTo($r->user()->id, $c->id);
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
        $v5 = $s->rules_version >= 5;
        $rules = ['answers' => 'required|array|min:1|max:20000', 'answers.*' => 'required|array:word_id,attempt_no,answer,self_assessed'.($v5 ? ',had_error,skipped' : ''), 'answers.*.word_id' => 'required|integer', 'answers.*.attempt_no' => 'required|integer|min:1', 'answers.*.answer' => $v5 ? 'present|nullable|string|max:5000' : 'required|string|max:5000', 'answers.*.self_assessed' => 'sometimes|boolean'];
        if ($v5) {
            $rules += ['answers.*.had_error' => 'sometimes|boolean', 'answers.*.skipped' => 'sometimes|boolean'];
        }
        $d = $r->validate($rules);
        $queue = array_keys($s->questions);
        $attempts = [];
        $skippedCount = 0;
        $wordSkips = [];
        $answeredCounts = [];
        $rightCounts = [];
        $wrongCounts = [];
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
            if ($s->mode === 'study' && $s->rules_version >= 4 && $number > 1) {
                $q['type'] = $q['retry_type'];
            }
            $hadError = (bool) ($a['had_error'] ?? false);
            $skipped = (bool) ($a['skipped'] ?? false);
            if ($hadError && ! ($s->mode === 'study' && in_array($q['type'], ['written', 'rearrange'], true))) {
                throw ValidationException::withMessages(['answers' => __('Live input errors are only recorded in Study written or rearranged answers.')]);
            }
            if ($skipped && ! ($s->mode === 'test' || ($s->mode === 'study' && $s->rules_version >= 6 && in_array($q['type'], ['written', 'rearrange'], true)))) {
                throw ValidationException::withMessages(['answers' => __('Skipping is only available in Test or Study written and rearranged answers.')]);
            }
            $self = (bool) ($a['self_assessed'] ?? false);
            if ($self && ! ($s->mode === 'study' && $q['type'] === 'written' && $q['answerKey'] === 'definition')) {
                throw ValidationException::withMessages(['answers' => __('Self assessment is allowed only for written definitions in Study.')]);
            }
            if ($skipped && ($self || $hadError || filled($a['answer'] ?? null))) {
                throw ValidationException::withMessages(['answers' => __('Skipped answers must be empty and cannot be self-assessed.')]);
            }
            if (! $skipped && ! filled(trim($a['answer'] ?? ''))) {
                throw ValidationException::withMessages(['answers' => __('Answer every question.')]);
            }
            if (! $skipped && $q['type'] === 'choice' && ! in_array($a['answer'], $q['options'], true)) {
                throw ValidationException::withMessages(['answers' => __('Choose one of the offered answers.')]);
            }
            $right = ! $skipped && ! $hadError && (self::normalize($a['answer']) === self::normalize($q['expected']) || $self);
            if ($number === 1) {
                $skippedCount += (int) $skipped;
                $correct += (int) $right;
                $assessed += (int) $self;
            }
            $rightCounts[$qi] = ($rightCounts[$qi] ?? 0) + (int) $right;
            $wrongCounts[$qi] = ($wrongCounts[$qi] ?? 0) + (int) ! $right;
            $answeredCounts[$qi] = ($answeredCounts[$qi] ?? 0) + (int) ! $skipped;
            // Legacy Study caps presentations; v6 counts answered turns separately from skips.
            $retry = $s->mode === 'study' && $s->rules_version >= 4
                ? ($rightCounts[$qi] < 2 && ($s->rules_version >= 6 ? $answeredCounts[$qi] < 3 : $number < 3))
                : ($s->rules_version >= 3 ? (! $right && $number < 3)
                : (($number === 1 && ! $right) || $number === 2 || ($number >= 3 && ! $right)));
            if ($skipped) {
                $wordSkips[$qi] = ($wordSkips[$qi] ?? 0) + 1;
                // The first Study v6 skip always returns later; a second skip ends this word.
                $retry = $s->mode === 'study' && $s->rules_version >= 6 && $wordSkips[$qi] < 2;
            }
            if ($retry) {
                $queue[] = $qi;
            }
            $s->answers()->create(['word_id' => in_array($q['id'], $existing, true) ? $q['id'] : null, 'position' => $position, 'question_index' => $qi, 'attempt_no' => $number, 'prompt' => $q['prompt'], 'expected' => $q['expected'], 'answer' => $a['answer'], 'correct' => $right, 'self_assessed' => $self, 'had_error' => $hadError, 'skipped' => $skipped]);
        }
        if (count($d['answers']) !== count($queue)) {
            throw ValidationException::withMessages(['answers' => __('Finish all questions and required retries before saving.')]);
        }
        if ($s->mode === 'study' && $s->rules_version >= 4) {
            $current = StudyMastery::forLearner($s->collection_id, $s->user_id);
            $questions = $s->questions;
            $attached = Collection::findOrFail($s->collection_id)->words()->pluck('words.id')->all();
            foreach ($questions as $qi => &$q) {
                $state = $current[$q['id']] ?? ['stage' => 'new', 'qualified' => false, 'baseline' => null];
                $fresh = ($q['prior_baseline'] ?? null) === $state['baseline'] && in_array($q['id'], $attached, true);
                $qualified = ($rightCounts[$qi] ?? 0) >= 2 && ($wordSkips[$qi] ?? 0) < 2;
                $reinforced = $qualified && ($q['prior_qualified'] ?? false) && ($wrongCounts[$qi] ?? 0) === 0;
                $q['mastery_applied'] = $fresh;
                $q['mastery_stage'] = $fresh ? ($reinforced ? 'reinforced' : 'learning') : $state['stage'];
                $q['mastery_qualified'] = $fresh ? $qualified : $state['qualified'];
            }
            unset($q);
            $s->questions = $questions;
        }
        $s->update(['correct_count' => $correct, 'self_assessed_count' => $assessed, 'completed_at' => now(), 'total_attempts' => count($d['answers']), 'skipped_count' => $skippedCount]);

        return response()->json($this->completed($s));
    }

    private function completed(StudySession $s): array
    {
        $answers = $s->answers()->orderBy('position')->get();

        $results = $answers->map(function ($a) use ($s) {
            $q = $s->questions[$a->question_index ?? $a->position];
            if ($s->mode === 'study' && $s->rules_version >= 4 && $a->attempt_no > 1) {
                $q['type'] = $q['retry_type'];
            }

            return $q + ['attempt_no' => $a->attempt_no, 'first_attempt' => $a->attempt_no === 1, 'answer' => $a->answer ?? '', 'correct' => $a->correct, 'self_assessed' => $a->self_assessed, 'had_error' => $a->had_error, 'skipped' => $a->skipped];
        })->all();

        return ['mastery' => $s->mode === 'study' && $s->rules_version >= 4 ? array_map(fn ($q) => ['word_id' => $q['id'], 'stage' => $q['mastery_stage'], 'qualified' => $q['mastery_qualified'], 'applied' => $q['mastery_applied']], $s->questions) : [], 'session' => $s->result(), 'results' => $results, 'first_results' => array_values(array_filter($results, fn ($r) => $r['first_attempt']))];
    }
}
