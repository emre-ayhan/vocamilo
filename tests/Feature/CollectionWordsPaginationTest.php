<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CollectionWordsPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(int $count): array
    {
        $owner = User::factory()->create();
        $c = $owner->collections()->create(['title' => 'Paged collection']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $words = collect();
        for ($i = 1; $i <= $count; $i++) {
            $word = $owner->words()->create(['term' => 'Word '.$i, 'definition' => 'Definition '.$i, 'note' => 'OWNER SECRET '.$i]);
            $c->words()->attach($word->id, ['added_at' => now()]);
            $words->push($word);
        }

        return [$owner, $c, $words];
    }

    public function test_boundaries_clamp_empty_and_exact_fifteen_pages(): void
    {
        foreach ([0, 15, 16, 31] as $count) {
            [$owner, $c, $words] = $this->fixture($count);
            $last = max(1, (int) ceil($count / 15));
            $this->actingAs($owner)->getJson('/collections/'.$c->id.'/words?words_page=999999')
                ->assertOk()->assertJsonPath('current_page', $last)->assertJsonPath('last_page', $last)
                ->assertJsonPath('total', $count)->assertJsonPath('per_page', 15)
                ->assertJsonPath('from', $count ? ($last - 1) * 15 + 1 : null)
                ->assertJsonPath('to', $count ?: null)
                ->assertJsonCount($count ? $count - ($last - 1) * 15 : 0, 'data');
            $first = $this->getJson('/collections/'.$c->id.'/words')->assertOk();
            $this->assertSame($words->reverse()->take(15)->pluck('id')->values()->all(), array_column($first->json('data'), 'id'));
            $this->get('/collections/'.$c->id.'?words_page=999999')->assertInertia(fn (Assert $p) => $p
                ->where('collection.word_pagination.current_page', $last)->where('collection.word_pagination.total', $count)
                ->has('collection.words', $count ? $count - ($last - 1) * 15 : 0));
        }
    }

    public function test_all_pages_are_disjoint_newest_added_then_unique_id_and_study_is_full(): void
    {
        [$owner, $c, $words] = $this->fixture(31);
        $c->words()->updateExistingPivot($words[0]->id, ['added_at' => now()->addMinute()]);
        $seen = [];
        $this->actingAs($owner);
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson('/collections/'.$c->id.'/words?words_page='.$page)->assertOk()->assertJsonPath('current_page', $page);
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        }
        $this->assertSame([$words[0]->id, ...$words->slice(1)->reverse()->pluck('id')->all()], $seen);
        $this->assertCount(31, array_unique($seen));
        $this->get('/collections/'.$c->id.'/study?mode=study&words_page=3')->assertInertia(fn (Assert $p) => $p->has('words', 31));
        Auth::logout();
        $this->get('/s/'.$c->share_uuid.'/study?mode=study&words_page=3')->assertInertia(fn (Assert $p) => $p->has('words', 31))->assertDontSee('OWNER SECRET');
    }

    public function test_page_input_validation_prevents_invalid_offsets_without_mutation(): void
    {
        [$owner, $c, $words] = $this->fixture(16);
        $this->actingAs($owner);
        foreach (['0', '-1', '1.5', 'abc', '999999999999999999999999999999999', ['2']] as $bad) {
            $query = http_build_query(['words_page' => $bad]);
            $this->getJson('/collections/'.$c->id.'/words?'.$query)->assertUnprocessable()->assertJsonValidationErrors('words_page');
            $this->getJson('/s/'.$c->share_uuid.'/words?'.$query)->assertUnprocessable()->assertJsonValidationErrors('words_page');
            $this->deleteJson('/collections/'.$c->id.'/words/'.$words[0]->id, ['words_page' => $bad])->assertUnprocessable();
        }
        $this->assertSame(16, $c->words()->count());
    }

    public function test_delete_preserves_page_clamps_last_and_never_follows_external_referer(): void
    {
        [$owner, $c, $words] = $this->fixture(31);
        $pool = $owner->wordPools()->create(['name' => 'Chosen', 'language' => 'en']);
        $this->actingAs($owner)->withHeader('Referer', 'https://evil.example/steal')
            ->delete('/collections/'.$c->id.'/words/'.$words[0]->id, ['words_page' => 3, 'pool_ids' => [$pool->id], 'next' => 'https://evil.example'])
            ->assertRedirect('/collections/'.$c->id.'?'.http_build_query(['words_page' => 2, 'pool_ids' => [$pool->id]]));
        $this->assertDatabaseHas('words', ['id' => $words[0]->id]);
        $this->delete('/collections/'.$c->id.'/words/'.$words[1]->id, ['words_page' => 2])->assertRedirect('/collections/'.$c->id.'?words_page=2');
        $this->getJson('/collections/'.$c->id.'/words?words_page=3')->assertJsonPath('current_page', 2)->assertJsonPath('total', 29);
        [$owner2, $single, $only] = $this->fixture(1);
        $this->actingAs($owner2)->delete('/collections/'.$single->id.'/words/'.$only[0]->id, ['words_page' => 999])->assertRedirect('/collections/'.$single->id.'?words_page=1');
    }

    public function test_invalid_or_foreign_filters_and_foreign_deletes_cannot_mutate(): void
    {
        [$owner, $c, $words] = $this->fixture(16);
        $foreign = User::factory()->create();
        $pool = $foreign->wordPools()->create(['name' => 'Private', 'language' => 'en']);
        $this->actingAs($owner)->deleteJson('/collections/'.$c->id.'/words/'.$words[0]->id, ['words_page' => 2, 'pool_ids' => [$pool->id]])->assertNotFound();
        $this->deleteJson('/collections/'.$c->id.'/words/'.$words[0]->id, ['pool_ids' => 'bad'])->assertUnprocessable();
        $this->actingAs($foreign)->deleteJson('/collections/'.$c->id.'/words/'.$words[0]->id, ['words_page' => 2])->assertNotFound();
        $this->assertSame(16, $c->words()->count());
    }

    public function test_member_guest_and_owner_pages_never_cross_private_note_boundaries(): void
    {
        [$owner, $c, $words] = $this->fixture(31);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $c->members()->attach($member, ['progress_consented_at' => now(), 'progress_consent_version' => 2]);
        DB::table('user_word_notes')->insert([
            ['user_id' => $member->id, 'word_id' => $words[0]->id, 'note' => 'MY PRIVATE NOTE'],
            ['user_id' => $other->id, 'word_id' => $words[0]->id, 'note' => 'OTHER SECRET'],
        ]);
        $this->actingAs($owner)->getJson('/collections/'.$c->id.'/words?words_page=3')->assertJsonPath('data.0.note', 'OWNER SECRET 1')->assertDontSee('MY PRIVATE NOTE')->assertDontSee('OTHER SECRET');
        $this->actingAs($member)->getJson('/collections/'.$c->id.'/words?words_page=3')->assertJsonPath('data.0.note', 'MY PRIVATE NOTE')->assertDontSee('OWNER SECRET')->assertDontSee('OTHER SECRET');
        $this->deleteJson('/collections/'.$c->id.'/words/'.$words[0]->id, ['words_page' => 3])->assertNotFound();
        $this->actingAs($other)->getJson('/collections/'.$c->id.'/words?words_page=3')->assertNotFound();
        Auth::logout();
        $public = $this->getJson('/s/'.$c->share_uuid.'/words?words_page=3')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('SECRET')->assertDontSee('MY PRIVATE NOTE');
        $this->assertSame(['id', 'term', 'definition', 'text_direction'], array_keys($public->json('data.0')));
        $this->getJson('/collections/'.$c->id.'/words?words_page=3')->assertUnauthorized();
        $c->forceFill(['share_access' => 'signed_in'])->save();
        $this->getJson('/s/'.$c->share_uuid.'/words?words_page=3')->assertUnauthorized();
        $this->actingAs($member);
        $c->blockedUsers()->attach($member);
        $this->getJson('/collections/'.$c->id.'/words?words_page=3')->assertNotFound();
        $this->getJson('/s/'.$c->share_uuid.'/words?words_page=3')->assertNotFound();
    }
}
