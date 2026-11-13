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
        $uuid = $r->session()->pull('pending_collection_uuid');
        if (! $r->user() || ! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }
        $id = DB::transaction(function () use ($r, $uuid) {
            $c = Collection::where('share_uuid', $uuid)->lockForUpdate()->first();
            if (! $c) {
                return null;
            }
            if ($c->user_id !== $r->user()->id) {
                $c->members()->syncWithoutDetaching([$r->user()->id]);
            }

            return $c->id;
        });

        return $id ? redirect('/collections/'.$id) : null;
    }
}
