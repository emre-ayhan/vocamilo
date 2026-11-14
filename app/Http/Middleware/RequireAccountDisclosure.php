<?php

namespace App\Http\Middleware;

use App\Support\AdminAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireAccountDisclosure
{
    public const VERSION = 1;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if ($user->suspended_at) {
            if ($request->is('admin', 'admin/*')) {
                AdminAudit::record($user->id, 'denied_suspended');
            }
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, __('This account is suspended.'));
        }
        $cancelPending = $request->isMethod('POST') && preg_match('#^s/[^/]+/join/cancel$#', $request->path());
        if (! $cancelPending && ((int) $user->admin_disclosure_version !== self::VERSION || ! $user->admin_disclosure_accepted_at) && ! $request->is('account/disclosure', 'logout', 'locale')) {
            if ($request->is('admin', 'admin/*')) {
                AdminAudit::record($user->id, 'denied_disclosure');
            }
            if ($request->isMethod('GET')) {
                $request->session()->put('disclosure_return_to', '/'.$request->path().($request->getQueryString() ? '?'.$request->getQueryString() : ''));
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => __('Please review the administrator access disclosure.'), 'disclosure_required' => true, 'url' => '/account/disclosure'], 409);
            }

            return redirect('/account/disclosure');
        }

        return $next($request);
    }
}
