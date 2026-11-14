<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VocabularyController extends Controller
{
    public function dashboard(Request $r)
    {
        $accessible = Collection::where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere(fn ($q) => $q->whereNotNull('share_uuid')->whereDoesntHave('blockedUsers', fn ($q) => $q->where('users.id', $r->user()->id))->whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))));

        if ($r->user()->is_admin) {
            $accessible->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhereHas('user', fn ($q) => $q->where('admin_disclosure_version', 1)->whereNotNull('admin_disclosure_accepted_at')));
        }

        return Inertia::render('Dashboard', ['stats' => ['words' => $r->user()->words()->count(), 'collections' => (clone $accessible)->count(), 'shared' => $r->user()->collections()->whereNotNull('share_uuid')->count(), 'joined' => (clone $accessible)->where('user_id', '!=', $r->user()->id)->count()], 'collections' => (clone $accessible)->withCount('words')->latest()->take(6)->get()->each(function ($c) use ($r) {
            if ($r->user()->is_admin) {
                AdminAudit::record($r->user()->id, 'collection_dashboard_item', $c->id);
            }
        })->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'share_uuid' => $c->share_uuid, 'words_count' => $c->words_count, 'isOwner' => $c->user_id === $r->user()->id]), 'recentWords' => $r->user()->words()->latest()->take(5)->get()]);
    }

    public function index(Request $r)
    {
        $d = $r->validate(['search' => 'nullable|string|max:200', 'pool_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        if (isset($d['pool_id'])) {
            $pool = $r->user()->wordPools()->findOrFail($d['pool_id']);
            $query = array_intersect_key($d, array_flip(['search', 'page']));

            return redirect('/word-pools/'.$pool->id.($query ? '?'.http_build_query($query) : ''));
        }

        return Inertia::render('Words/Pools', ['pools' => $r->user()->wordPools()->withCount('words')->orderBy('name')->get(['id', 'name', 'language'])]);
    }

    public function pool(Request $r, int $pool)
    {
        $selected = $r->user()->wordPools()->findOrFail($pool);
        $d = $r->validate(['search' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        $search = trim($d['search'] ?? '');
        $words = $r->user()->words()->whereHas('pools', fn ($q) => $q->where('word_pools.id', $selected->id));

        return Inertia::render('Words/Index', ['words' => $words->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('term', 'like', '%'.$search.'%')->orWhere('definition', 'like', '%'.$search.'%')))->latest()->paginate(30)->withQueryString(), 'pools' => $r->user()->wordPools()->withCount('words')->orderBy('name')->get(), 'selectedPool' => $selected, 'filters' => ['search' => $search, 'pool_id' => $selected->id]]);
    }

    public function store(Request $r)
    {
        $d = $this->data($r);
        $pool = $r->validate(['pool_id' => 'required|integer|min:1'])['pool_id'];
        DB::transaction(function () use ($r, $d, $pool) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $p = $r->user()->wordPools()->findOrFail($pool);
            $w = $r->user()->words()->create($d);
            $p->words()->attach($w);
        });

        return back()->with('success', __('Word added to your pool.'));
    }

    public function update(Request $r, int $word)
    {
        $d = $this->data($r);
        DB::transaction(function () use ($r, $word, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->words()->findOrFail($word)->update($d);
        });

        return back()->with('success', __('Word updated.'));
    }

    public function destroy(Request $r, int $word)
    {
        DB::transaction(function () use ($r, $word) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->words()->findOrFail($word)->delete();
        });

        return back()->with('success', __('Word removed from your pool and collections.'));
    }

    private function data(Request $r): array
    {
        return $r->validate(['term' => 'required|string|max:500', 'definition' => 'required|string|max:5000', 'note' => 'nullable|string|max:5000']);
    }
}
