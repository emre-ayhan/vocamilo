<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CollectionController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Collections/Index', ['collections' => $r->user()->collections()->withCount('words')->latest()->get()]);
    }

    public function store(Request $r)
    {
        $c = $r->user()->collections()->create($this->data($r));

        return redirect('/collections/'.$c->id)->with('success', 'Collection created.');
    }

    public function show(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, false), 'shared' => false, 'availableWords' => $r->user()->words()->select('id', 'term', 'definition')->orderBy('term')->get()]);
    }

    public function update(Request $r, int $collection)
    {
        $this->owned($r, $collection)->update($this->data($r));

        return back()->with('success', 'Collection updated.');
    }

    public function destroy(Request $r, int $collection)
    {
        $this->owned($r, $collection)->delete();

        return redirect('/collections')->with('success', 'Collection deleted. Your words remain in the pool.');
    }

    public function addWords(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        $d = $r->validate(['word_ids' => 'required|array|max:500', 'word_ids.*' => 'required|integer|distinct']);
        $ids = $r->user()->words()->whereIn('id', $d['word_ids'])->pluck('id');
        abort_if($ids->count() !== count($d['word_ids']), 422, 'Choose words from your own pool.');
        $c->words()->syncWithoutDetaching($ids);

        return back()->with('success', 'Words added.');
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

        return back()->with('success', 'Anyone with this link can study this collection. Personal notes stay private.');
    }

    public function unshare(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        $c->share_uuid = null;
        $c->save();

        return back()->with('success', 'Sharing disabled. The previous link no longer works.');
    }

    public function publicShow(string $uuid)
    {
        $c = $this->shared($uuid);

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, true), 'shared' => true, 'availableWords' => []]);
    }

    public function study(Request $r, int $collection)
    {
        return $this->session($r, $this->owned($r, $collection), false);
    }

    public function publicStudy(Request $r, string $uuid)
    {
        return $this->session($r, $this->shared($uuid), true);
    }

    private function session(Request $r, Collection $c, bool $shared)
    {
        $mode = $r->query('mode', 'flashcards');
        abort_unless(in_array($mode, ['flashcards', 'study', 'test'], true), 422);
        $p = $this->payload($c, $shared);

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

    private function payload(Collection $c, bool $public): array
    {
        return ['id' => $public ? null : $c->id, 'title' => $c->title, 'description' => $c->description, 'share_uuid' => $c->share_uuid, 'words' => $c->words()->get()->map(fn ($w) => $public ? $w->only('id', 'term', 'definition') : $w->only('id', 'term', 'definition', 'note'))->values()];
    }
}
