<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireAccountDisclosure;
use App\Support\CollectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AccountDisclosureController extends Controller
{
    public function show()
    {
        return Inertia::render('Account/Disclosure', ['version' => RequireAccountDisclosure::VERSION]);
    }

    public function accept(Request $r)
    {
        $r->validate(['accepted' => 'required|accepted']);
        $r->user()->forceFill(['admin_disclosure_version' => RequireAccountDisclosure::VERSION, 'admin_disclosure_accepted_at' => now()])->save();
        $join = CollectionAccess::finishPendingJoin($r);
        if ($join) {
            return $join;
        }
        $target = $r->session()->pull('disclosure_return_to') ?? $r->session()->pull('url.intended', '/dashboard');
        $origin = $r->getSchemeAndHttpHost();
        if (is_string($target) && str_starts_with($target, $origin.'/')) {
            $target = substr($target, strlen($origin));
        }
        if (! is_string($target) || ! str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '\\') || preg_match('/[\x00-\x20]/', $target)) {
            $target = '/dashboard';
        }

        return redirect($target);
    }

    public function decline(Request $r)
    {
        $r->user()->forceFill(['admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null])->save();
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
