<?php

namespace App\Http\Middleware;

use App\Support\AdminAudit;
use Closure;
use Illuminate\Http\Request;

class RequireAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_admin) {
            AdminAudit::record($request->user()?->id, 'denied_admin');
            abort(403);
        }

        return $next($request);
    }
}
