<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->user()?->locale ?? $request->cookie('locale', 'en');
        app()->setLocale(in_array($locale, ['en', 'pt'], true) ? $locale : 'en');

        return $next($request);
    }
}
