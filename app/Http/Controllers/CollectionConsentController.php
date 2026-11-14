<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\StudySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CollectionConsentController extends Controller
{
    public function joinForm(Request $r, string $uuid)
    {
        abort_unless(Str::isUuid($uuid), 404);
        $c = Collection::where('share_uuid', $uuid)->firstOrFail();
        abort_if($r->user() && $c->isBlocked($r->user()->id), 404);
        if (! $r->user() && $c->share_access === 'signed_in') {
            $r->session()->put('url.intended', '/s/'.$uuid.'/join');

            return redirect('/login');
        }

        return Inertia::render('Collections/Consent', ['collection' => ['id' => null, 'title' => $c->title], 'joinUuid' => $uuid, 'existingMember' => false]);
    }

    public function cancelJoin(Request $r, string $uuid)
    {
        abort_unless(Str::isUuid($uuid), 404);
        if ($r->session()->get('pending_collection_uuid') === $uuid) {
            $r->session()->forget(['pending_collection_uuid', 'pending_collection_progress_consent', 'pending_collection_consent_expires_at']);
        }

        return redirect('/s/'.$uuid);
    }

    private function membership(Request $r, int $collection): Collection
    {
        return Collection::whereKey($collection)->whereNotNull('share_uuid')->whereDoesntHave('blockedUsers', fn ($q) => $q->where('users.id', $r->user()->id))->whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))->firstOrFail();
    }

    public function show(Request $r, int $collection)
    {
        $c = $this->membership($r, $collection);

        return Inertia::render('Collections/Consent', ['collection' => $c->only('id', 'title'), 'joinUuid' => null, 'existingMember' => true]);
    }

    public function accept(Request $r, int $collection)
    {
        $r->validate(['progress_consent' => 'required|accepted']);
        DB::transaction(function () use ($r, $collection) {
            Collection::whereKey($collection)->lockForUpdate()->firstOrFail();
            $c = $this->membership($r, $collection);
            $c->members()->updateExistingPivot($r->user()->id, ['progress_consented_at' => now()]);
        });

        return redirect('/collections/'.$collection);
    }

    public function leave(Request $r, int $collection)
    {
        DB::transaction(function () use ($r, $collection) {
            $c = Collection::whereKey($collection)->lockForUpdate()->firstOrFail();
            abort_unless($c->members()->where('users.id', $r->user()->id)->exists(), 404);
            $c->members()->detach($r->user()->id);
        });

        return redirect('/collections')->with('success', __('Membership removed. Your private notes and study history have been preserved.'));
    }

    public function progress(Request $r, int $collection)
    {
        $c = $r->user()->collections()->findOrFail($collection);
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $search = trim($filters['q'] ?? '');
        $members = $c->members()->when(! $c->share_uuid, fn ($q) => $q->whereRaw('1 = 0'))->when($search !== '', fn ($q) => $q->where('users.name', 'like', '%'.$search.'%'))->wherePivotNotNull('progress_consented_at')
            ->where('users.admin_disclosure_version', 1)->whereNotNull('users.admin_disclosure_accepted_at')->whereNull('users.suspended_at')
            ->whereNotIn('users.id', $c->blockedUsers()->pluck('users.id'))
            ->orderBy('users.name')->orderBy('users.id')->paginate(15, ['users.id', 'users.name'])->withQueryString();

        return Inertia::render('Collections/Progress', ['collection' => $c->only('id', 'title'), 'filters' => ['q' => $search], 'members' => $members->through(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'consented_at' => $member->pivot->progress_consented_at, 'summary' => StudySession::summaryFor($c, $member->id)])]);
    }
}
