<?php

namespace App\Support;

use App\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionAccess
{
    public static function finishPendingJoin(Request $r): ?RedirectResponse
    {
        if ($r->user()?->suspended_at) {
            $r->session()->forget(['pending_collection_uuid', 'pending_collection_progress_consent', 'pending_collection_consent_expires_at']);

            return redirect('/dashboard');
        }
        if ($r->user() && ((int) $r->user()->admin_disclosure_version !== 1 || ! $r->user()->admin_disclosure_accepted_at)) {
            return redirect('/account/disclosure');
        }
        $uuid = $r->session()->pull('pending_collection_uuid');
        $accepted = $r->session()->pull('pending_collection_progress_consent') === true;
        $expires = $r->session()->pull('pending_collection_consent_expires_at');
        $consented = $accepted && is_int($expires) && $expires > now()->timestamp;
        if (! $r->user() || ! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }
        if (! $consented) {
            $c = Collection::where('share_uuid', $uuid)->first();

            return $c && ! $c->isBlocked($r->user()->id) ? redirect('/s/'.$uuid.'/join') : null;
        }
        $id = DB::transaction(function () use ($r, $uuid) {
            $c = Collection::where('share_uuid', $uuid)->lockForUpdate()->first();
            if (! $c || $c->isBlocked($r->user()->id)) {
                return null;
            }
            if ($c->user_id !== $r->user()->id) {
                $c->members()->syncWithoutDetaching([$r->user()->id => ['progress_consented_at' => now()]]);
            }

            return $c->id;
        });

        return $id ? redirect('/collections/'.$id) : null;
    }
}
