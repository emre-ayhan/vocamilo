<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class VocabularyController extends Controller
{
    public function dashboard(Request $r)
    {
        return Inertia::render('Dashboard', ['stats' => ['words' => $r->user()->words()->count(), 'collections' => $r->user()->collections()->count(), 'shared' => $r->user()->collections()->whereNotNull('share_uuid')->count()], 'collections' => $r->user()->collections()->withCount('words')->latest()->take(6)->get(), 'recentWords' => $r->user()->words()->latest()->take(5)->get()]);
    }

    public function index(Request $r)
    {
        $search = mb_substr((string) $r->query('search', ''), 0, 200);

        return Inertia::render('Words/Index', ['words' => $r->user()->words()->when($search, fn ($q) => $q->where(fn ($q) => $q->where('term', 'like', '%'.$search.'%')->orWhere('definition', 'like', '%'.$search.'%')))->latest()->paginate(30)->withQueryString(), 'filters' => ['search' => $search]]);
    }

    public function store(Request $r)
    {
        $r->user()->words()->create($this->data($r));

        return back()->with('success', 'Word added to your pool.');
    }

    public function update(Request $r, int $word)
    {
        $r->user()->words()->findOrFail($word)->update($this->data($r));

        return back()->with('success', 'Word updated.');
    }

    public function destroy(Request $r, int $word)
    {
        $r->user()->words()->findOrFail($word)->delete();

        return back()->with('success', 'Word removed from your pool and collections.');
    }

    private function data(Request $r): array
    {
        return $r->validate(['term' => 'required|string|max:500', 'definition' => 'required|string|max:5000', 'note' => 'nullable|string|max:5000']);
    }
}
