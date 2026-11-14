<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WordPoolController extends Controller
{
    private function data(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:150', 'language' => ['required', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/']]);
    }

    public function available(Request $r, int $pool)
    {
        $r->user()->wordPools()->findOrFail($pool);
        $d = $r->validate(['search' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        $search = trim($d['search'] ?? '');

        return response()->json($r->user()->words()->whereDoesntHave('pools', fn ($q) => $q->where('word_pools.id', $pool))->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('term', 'like', '%'.$search.'%')->orWhere('definition', 'like', '%'.$search.'%')))->orderBy('term')->orderBy('id')->paginate(30, ['id', 'term', 'definition'])->withQueryString());
    }

    public function store(Request $r)
    {
        $d = $this->data($r);
        $p = DB::transaction(function () use ($r, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();

            return $r->user()->wordPools()->create($d);
        });

        return redirect('/words?pool_id='.$p->id);
    }

    public function update(Request $r, int $pool)
    {
        $d = $this->data($r);
        DB::transaction(function () use ($r, $pool, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->wordPools()->findOrFail($pool)->update($d);
        });

        return back();
    }

    public function destroy(Request $r, int $pool)
    {
        DB::transaction(function () use ($r, $pool) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $r->user()->wordPools()->findOrFail($pool)->delete();
        });

        return redirect('/words');
    }

    public function attach(Request $r, int $pool)
    {
        $d = $r->validate(['word_ids' => 'required|array|min:1|max:500', 'word_ids.*' => 'required|integer|distinct']);
        DB::transaction(function () use ($r, $pool, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $p = $r->user()->wordPools()->findOrFail($pool);
            $ids = $r->user()->words()->whereIn('id', $d['word_ids'])->pluck('id');
            abort_unless($ids->count() === count($d['word_ids']), 422);
            $p->words()->syncWithoutDetaching($ids);
        });

        return back();
    }

    public function detach(Request $r, int $pool, int $word)
    {
        DB::transaction(function () use ($r, $pool, $word) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $p = $r->user()->wordPools()->findOrFail($pool);
            $r->user()->words()->findOrFail($word);
            $p->words()->detach($word);
        });

        return back();
    }
}
