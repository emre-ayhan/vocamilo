<?php

namespace App\Http\Middleware;

use App\Models\Collection;
use App\Support\AdminAudit;
use Closure;
use Illuminate\Http\Request;

class AuditAdministratorCollections
{
    public function handle(Request $r, Closure $next)
    {
        $user = $r->user();
        if (! $user?->is_admin || ($r->isMethod('POST') && preg_match('#^s/[^/]+/join/cancel$#', $r->path()))) {
            return $next($r);
        }
        $collection = null;
        $id = null;
        if (preg_match('#^collections/([0-9]+)(?:/|$)#', $r->path(), $match)) {
            $id = (int) $match[1];
            $collection = Collection::find($id);
        } elseif (preg_match('#^s/([^/]+)(?:/|$)#', $r->path(), $match)) {
            $collection = Collection::where('share_uuid', $match[1])->first();
            $id = $collection?->id;
        } else {
            return $next($r);
        }
        AdminAudit::record($user->id, 'collection_learner_request', $id);
        if ($collection && $collection->user_id !== $user->id) {
            $owner = $collection->user;
            abort_unless($owner && (int) $owner->admin_disclosure_version === RequireAccountDisclosure::VERSION && $owner->admin_disclosure_accepted_at, 404);
        }

        return $next($r);
    }
}
