<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\StudySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CollectionController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Collections/Index', ['collections' => Collection::where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere(fn ($q) => $q->whereNotNull('share_uuid')->whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))))->withCount('words')->latest()->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'share_uuid' => $c->share_uuid, 'words_count' => $c->words_count, 'isOwner' => $c->user_id === $r->user()->id])]);
    }

    public function store(Request $r)
    {
        $c = $r->user()->collections()->create($this->data($r));

        return redirect('/collections/'.$c->id)->with('success', __('Collection created.'));
    }

    public function show(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $owner = $c->user_id === $r->user()->id;

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, false, $r->user()->id, true), 'shared' => false, 'isOwner' => $owner, 'member' => ! $owner, 'summary' => StudySession::summaryFor($c, $r->user()->id), 'availableWords' => $owner ? $r->user()->words()->whereDoesntHave('collections', fn ($q) => $q->where('collections.id', $c->id))->select('id', 'term', 'definition')->orderBy('term')->get() : []]);
    }

    public function words(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);

        return response()->json($this->wordPage($c, $r->user()->id));
    }

    public function publicWords(string $uuid)
    {
        return response()->json($this->wordPage($this->shared($uuid), null));
    }

    public function join(Request $r, string $uuid)
    {
        $c = $this->shared($uuid);
        if (! $r->user()) {
            $r->session()->put('pending_collection_uuid', $uuid);

            return redirect('/login');
        }
        DB::transaction(function () use ($c, $r, $uuid) {
            $locked = Collection::whereKey($c->id)->where('share_uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== $r->user()->id) {
                $locked->members()->syncWithoutDetaching([$r->user()->id]);
            }
        });

        return redirect('/collections/'.$c->id);
    }

    public function note(Request $r, int $collection, int $word)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $w = $c->words()->findOrFail($word);
        $d = $r->validate(['note' => 'nullable|string|max:5000']);
        if ($c->user_id === $r->user()->id) {
            $w->update(['note' => $d['note'] ?? null]);
        } else {
            DB::table('collection_notes')->updateOrInsert(['collection_id' => $c->id, 'user_id' => $r->user()->id, 'word_id' => $word], ['note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', __('Note saved.'));
    }

    public function update(Request $r, int $collection)
    {
        $this->owned($r, $collection)->update($this->data($r));

        return back()->with('success', __('Collection updated.'));
    }

    public function destroy(Request $r, int $collection)
    {
        $this->owned($r, $collection)->delete();

        return redirect('/collections')->with('success', __('Collection deleted. Your words remain in the pool.'));
    }

    public function addWords(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        $d = $r->validate(['word_ids' => 'required|array|max:500', 'word_ids.*' => 'required|integer|distinct']);
        $ids = $r->user()->words()->whereIn('id', $d['word_ids'])->pluck('id');
        abort_if($ids->count() !== count($d['word_ids']), 422, __('Choose words from your own pool.'));
        $existing = $c->words()->pluck('words.id');
        $new = $ids->diff($existing);
        $c->words()->syncWithoutDetaching($new->mapWithKeys(fn ($id) => [$id => ['added_at' => now()]])->all());

        return back()->with('success', __('Words added.'));
    }

    public function removeWord(Request $r, int $collection, int $word)
    {
        $this->owned($r, $collection)->words()->detach($word);

        return back();
    }

    public function share(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        if (! $c->share_uuid) {
            $c->share_uuid = (string) Str::uuid();
            $c->save();
        }

        return back()->with('success', __('Anyone with this link can study this collection. Personal notes stay private.'));
    }

    public function unshare(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        DB::transaction(function () use ($c) {
            $c = Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $c->share_uuid = null;
            $c->save();
            $c->members()->detach();
        });

        return back()->with('success', __('Sharing disabled. The previous link no longer works.'));
    }

    public function publicShow(string $uuid)
    {
        $c = $this->shared($uuid);

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, true, null, true), 'shared' => true, 'isOwner' => false, 'member' => false, 'availableWords' => []]);
    }

    public function study(Request $r, int $collection)
    {
        return $this->session($r, Collection::accessibleTo($r->user()->id, $collection), false);
    }

    public function publicStudy(Request $r, string $uuid)
    {
        return $this->session($r, $this->shared($uuid), true);
    }

    private function session(Request $r, Collection $c, bool $shared)
    {
        $mode = $r->query('mode', 'flashcards');
        abort_unless(in_array($mode, ['flashcards', 'study', 'test'], true), 422);
        $p = $this->payload($c, $shared, $shared ? null : $r->user()->id);

        return Inertia::render('Study/Session', ['collection' => $p, 'words' => $p['words'], 'mode' => $mode, 'shared' => $shared, 'settings' => []]);
    }

    private function owned(Request $r, int $id): Collection
    {
        return $r->user()->collections()->findOrFail($id);
    }

    private function shared(string $uuid): Collection
    {
        abort_unless(Str::isUuid($uuid), 404);

        return Collection::where('share_uuid', $uuid)->firstOrFail();
    }

    private function data(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:150', 'description' => 'nullable|string|max:2000']);
    }

    private function wordData(Collection $c, $words, ?int $learner): array
    {
        $notes = $learner && $learner !== $c->user_id ? DB::table('collection_notes')->where('collection_id', $c->id)->where('user_id', $learner)->pluck('note', 'word_id') : collect();

        return $words->map(function ($w) use ($c, $learner, $notes) {
            $row = $w->only('id', 'term', 'definition');
            if ($learner) {
                $row['note'] = $learner === $c->user_id ? $w->note : ($notes[$w->id] ?? null);
            }

            return $row;
        })->values()->all();
    }

    private function wordPage(Collection $c, ?int $learner): array
    {
        // Cursor uses unique word id with added_at ordering; legacy rows get a deterministic epoch.
        $page = $c->words()->reorder()->select('words.*')->selectRaw("COALESCE(collection_word.added_at, '1970-01-01 00:00:00') as membership_added_at")->orderByDesc('membership_added_at')->orderByDesc('words.id')->cursorPaginate(15);

        return ['data' => $this->wordData($c, collect($page->items()), $learner), 'next_cursor' => $page->nextCursor()?->encode()];
    }

    private function payload(Collection $c, bool $public, ?int $learner = null, bool $paginate = false): array
    {
        $page = $paginate ? $this->wordPage($c, $learner) : null;

        return ['id' => $public ? null : $c->id, 'title' => $c->title, 'description' => $c->description, 'share_uuid' => $c->share_uuid, 'words' => $paginate ? $page['data'] : $this->wordData($c, $c->words()->get(), $learner), 'words_count' => $c->words()->count(), 'next_cursor' => $page['next_cursor'] ?? null];
    }
}
