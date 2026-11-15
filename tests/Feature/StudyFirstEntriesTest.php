<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use App\Support\LetterPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyFirstEntriesTest extends TestCase
{
    use RefreshDatabase;

    private function start(string $word = 'abcdefg', string $type = 'written'): array
    {
        $u = User::factory()->create();
        $c = $u->collections()->create(['title' => 'Letters']);
        $c->words()->attach($u->words()->create(['term' => $word, 'definition' => 'Definition'])->id);
        $this->actingAs($u);
        $s = $this->postJson('/collections/'.$c->id.'/sessions', ['mode' => 'study', 'settings' => ['type' => $type, 'direction' => 'term', 'order' => 'ordered', 'count' => 1]])->assertCreated()->assertJsonPath('rules_version', 7)->json();

        return [$c, $s, $s['questions'][0]];
    }

    private function answer($q, int $n = 1, int $wrong = 0): array
    {
        $entries = array_map(fn ($slot) => ['index' => $slot['index'], 'value' => $slot['expected']], $q['letter_layout'][$q['type']]);
        for ($i = 0; $i < $wrong; $i++) {
            $entries[$i]['value'] = 'Z';
        }

        return ['word_id' => $q['id'], 'attempt_no' => $n, 'answer' => $q['expected'], 'input_mode' => 'slots', 'first_entries' => $entries];
    }

    private function finish($c, $s, $a)
    {
        return $this->postJson('/collections/'.$c->id.'/sessions/'.$s['id'].'/complete', ['answers' => $a]);
    }

    public function test_written_hints_excluded_and_four_editable_one_wrong_is_seventy_five_percent(): void
    {
        [$c,$s,$q] = $this->start();
        $this->assertCount(4, $q['letter_layout']['written']);
        $r = $this->finish($c, $s, [$this->answer($q, 1, 1), $this->answer($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.total', 1)->assertJsonPath('session.total_attempts', 3)->assertJsonPath('results.0.correct', false)->assertJsonPath('mastery.0.qualified', true)->json();
        $this->finish($c, $s, [])->assertExactJson($r);
        $this->assertDatabaseCount('study_answers', 3);
    }

    public function test_exact_eighty_percent_passes_integer_boundary_and_retries_do_not_inflate_first_score(): void
    {
        [$c,$s,$q] = $this->start('abcde', 'rearrange');
        $this->assertCount(5, $q['letter_layout']['rearrange']);
        $this->finish($c, $s, [$this->answer($q, 1, 1), $this->answer($q, 2)])->assertOk()->assertJsonPath('session.correct', 1)->assertJsonPath('session.total', 1)->assertJsonPath('session.percent', 100)->assertJsonPath('session.total_attempts', 2);
        [$c,$s,$q] = $this->start('abcde', 'rearrange');
        $this->finish($c, $s, [$this->answer($q, 1, 2), $this->answer($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('session.correct', 0);
    }

    public function test_duplicate_hint_missing_multigrapheme_and_extra_entry_fields_are_rejected_atomically(): void
    {
        [$c,$s,$q] = $this->start();
        $valid = $this->answer($q);
        foreach (['duplicate', 'hint', 'missing', 'multi', 'extra', 'indexstring'] as $case) {
            $bad = $valid;
            if ($case === 'duplicate') {
                $bad['first_entries'][1]['index'] = $bad['first_entries'][0]['index'];
            }
            if ($case === 'hint') {
                $bad['first_entries'][0]['index'] = 1;
            }
            if ($case === 'missing') {
                array_pop($bad['first_entries']);
            }
            if ($case === 'multi') {
                $bad['first_entries'][0]['value'] = 'ab';
            }
            if ($case === 'extra') {
                $bad['first_entries'][0]['correct'] = true;
            }
            if ($case === 'indexstring') {
                $bad['first_entries'][0]['index'] = '0';
            }
            $this->finish($c, $s, [$bad, $this->answer($q, 2)])->assertUnprocessable();
        }$this->assertDatabaseCount('study_answers', 0);
    }

    public function test_skip_cannot_smuggle_letter_metrics_and_keeps_skip_semantics(): void
    {
        [$c,$s,$q] = $this->start();
        $bad = $this->answer($q);
        $bad['answer'] = null;
        $bad['skipped'] = true;
        $this->finish($c, $s, [$bad])->assertUnprocessable();
        $skip = ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => null, 'skipped' => true];
        $this->finish($c, $s, [$skip, $this->answer($q, 2), $this->answer($q, 3)])->assertOk()->assertJsonPath('session.correct', 0)->assertJsonPath('session.skipped', 1)->assertJsonPath('mastery.0.stage', 'learning');
    }

    public function test_unicode_layout_normalizes_compatibility_and_keeps_diacritics_single_grapheme(): void
    {
        foreach (['بِت', 'éx', 'İı', 'ﻻ', 'ﬁ'] as $word) {
            [$c,$s,$q] = $this->start($word, 'rearrange');
            $this->assertSame(LetterPlacement::parts($word), $q['letter_layout']['parts']);
            $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2)])->assertOk()->assertJsonPath('session.correct', 1);
        }
    }

    public function test_fallback_disallows_metrics_and_legacy_six_rejects_new_fields(): void
    {
        [$c,$s,$q] = $this->start(str_repeat('x', 241));
        $this->assertFalse($q['letter_layout']['supported']);
        $this->finish($c, $s, [$this->answer($q)])->assertUnprocessable();
        $text = ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => $q['expected'], 'input_mode' => 'text'];
        $this->finish($c, $s, [$text + ['first_entries' => []]])->assertUnprocessable();
        $second = $text;
        $second['attempt_no'] = 2;
        $this->finish($c, $s, [$text, $second])->assertOk()->assertJsonPath('session.correct', 1);
        [$c,$s,$q] = $this->start();
        StudySession::find($s['id'])->update(['rules_version' => 6]);
        $this->finish($c, $s, [$this->answer($q), $this->answer($q, 2)])->assertUnprocessable();
        $a = ['word_id' => $q['id'], 'attempt_no' => 1, 'answer' => $q['expected']];
        $b = $a;
        $b['attempt_no'] = 2;
        $this->finish($c, $s, [$a, $b])->assertOk();
    }

    public function test_final_answer_must_be_exact_even_with_perfect_first_entries_and_metrics_persist(): void
    {
        [$c, $s, $q] = $this->start('abcde', 'rearrange');
        $bad = $this->answer($q);
        $bad['answer'] = 'ZZZZZ';
        $this->finish($c, $s, [$bad, $this->answer($q, 2)])->assertUnprocessable();
        $this->assertDatabaseCount('study_answers', 0);
        $first = $this->answer($q, 1, 1);
        $first['had_error'] = true;
        $this->finish($c, $s, [$first, $this->answer($q, 2)])->assertOk()
            ->assertJsonPath('results.0.placement.correct_slots', 4)
            ->assertJsonPath('results.0.placement.total_slots', 5)
            ->assertJsonPath('results.0.placement.passed', true)
            ->assertJsonPath('session.correct', 1);
        $saved = StudySession::find($s['id']);
        $this->assertSame(4, $saved->questions[0]['placement_attempts'][1]['correct_slots']);
    }
}
