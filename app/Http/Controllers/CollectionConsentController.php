<?php

namespace App\Http\Controllers;

use App\Models\Collection;
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

        return Inertia::render('Collections/Consent', ['collection' => ['id' => null, 'title' => $c->title], 'joinUuid' => $uuid, 'existingMember' => false, 'consentVersion' => Collection::PROGRESS_CONSENT_VERSION]);
    }

    public function cancelJoin(Request $r, string $uuid)
    {
        abort_unless(Str::isUuid($uuid), 404);
        if ($r->session()->get('pending_collection_uuid') === $uuid) {
            $r->session()->forget(['pending_collection_uuid', 'pending_collection_progress_consent', 'pending_collection_consent_expires_at', 'pending_collection_consent_version']);
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

        return Inertia::render('Collections/Consent', ['collection' => $c->only('id', 'title'), 'joinUuid' => null, 'existingMember' => true, 'consentVersion' => Collection::PROGRESS_CONSENT_VERSION]);
    }

    public function accept(Request $r, int $collection)
    {
        $r->validate(['progress_consent' => 'required|accepted', 'consent_version' => 'required|integer|in:'.Collection::PROGRESS_CONSENT_VERSION]);
        DB::transaction(function () use ($r, $collection) {
            Collection::whereKey($collection)->lockForUpdate()->firstOrFail();
            $c = $this->membership($r, $collection);
            $c->members()->updateExistingPivot($r->user()->id, ['progress_consented_at' => now(), 'progress_consent_version' => Collection::PROGRESS_CONSENT_VERSION]);
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
        $r->user()->collections()->findOrFail($collection);
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = [];
        if (isset($filters['q'])) {
            $query['q'] = trim($filters['q']);
        }
        if (isset($filters['page'])) {
            $query['participants_page'] = $filters['page'];
        }

        return redirect('/collections/'.$collection.'/sharing/members'.($query ? '?'.http_build_query($query) : ''));
    }
}
