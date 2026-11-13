<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function login()
    {
        return Inertia::render('Auth/Login', ['googleEnabled' => GoogleAuthController::enabled()]);
    }

    public function register()
    {
        return Inertia::render('Auth/Register', ['googleEnabled' => GoogleAuthController::enabled()]);
    }

    public function store(Request $r)
    {
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(10)]]);
        $d['email'] = mb_strtolower($d['email']);
        $user = User::create($d);
        Auth::login($user);
        $r->session()->regenerate();

        return redirect('/dashboard');
    }

    public function authenticate(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $d['email'] = mb_strtolower($d['email']);
        if (! Auth::attempt($d, $r->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $r->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
