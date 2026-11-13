<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate(['locale' => 'required|in:en,pt']);
        if ($request->user()) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return back()->withCookie(cookie('locale', $data['locale'], 525600, '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
