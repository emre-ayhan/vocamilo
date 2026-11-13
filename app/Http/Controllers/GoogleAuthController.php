<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CollectionAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret')) && filled(config('services.google.redirect'));
    }

    public function redirect(Request $r)
    {
        $r->session()->forget('google_link');
        if (! self::enabled()) {
            return $this->error('Google sign-in is not configured yet.');
        }

        return Socialite::driver('google')->enablePKCE()->scopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $r)
    {
        $r->session()->forget('google_link');
        if (! self::enabled()) {
            return $this->error('Google sign-in is not configured yet.');
        }
        if ($r->has('error')) {
            $r->session()->forget(['state', 'code_verifier']);

            return $this->error('Google sign-in was cancelled. You can try again.');
        }
        try {
            $google = Socialite::driver('google')->enablePKCE()->user();
        } catch (InvalidStateException $e) {
            return $this->error('This sign-in session expired. Please start again.');
        } catch (\Throwable $e) {
            return $this->error('Google sign-in could not be completed. Please try again.');
        }
        // The provider fetches UserInfo server-to-server after the state-checked code exchange.
        $raw = $google->getRaw();
        $sub = (string) $google->getId();
        $email = mb_strtolower(trim((string) $google->getEmail()));
        $verified = ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
        if (! in_array($verified, [true, 1, '1', 'true'], true) || $sub === '' || strlen($sub) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return $this->error('Google must provide a verified email address to continue.');
        }
        $authoritative = str_ends_with($email, '@gmail.com') || (! empty($raw['hd']) && is_string($raw['hd']));
        $identity = ['sub' => $sub, 'email' => $email, 'name' => mb_substr(trim((string) $google->getName()) ?: 'Learner', 0, 100), 'authoritative' => $authoritative, 'expires_at' => time() + 600];
        $linked = User::where('google_id', $sub)->first();
        if ($linked) {
            return $this->signIn($r, $linked);
        }
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            if ($existing->google_id) {
                return $this->error('This email is already associated with another Google identity. Sign in using your existing method.');
            }
            $r->session()->put('google_link', $identity + ['user_id' => $existing->id]);

            return redirect('/auth/google/link');
        }
        try {
            $user = DB::transaction(function () use ($identity) {
                $user = new User(['name' => $identity['name'], 'email' => $identity['email'], 'password' => Str::random(64)]);
                $user->google_id = $identity['sub'];
                $user->locale = app()->getLocale();
                $user->email_verified_at = $identity['authoritative'] ? now() : null;
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->error('Your account changed during sign-in. Please start again.');
        }

        return $this->signIn($r, $user);
    }

    public function linkForm(Request $r)
    {
        $pending = $this->pending($r);
        if (! $pending) {
            return $this->error('The account-linking session expired. Start Google sign-in again.');
        }

        return Inertia::render('Auth/LinkGoogle', ['email' => $pending['email']]);
    }

    public function link(Request $r)
    {
        $r->validate(['password' => 'required|string']);
        $pending = $this->pending($r);
        if (! $pending) {
            return $this->error('The account-linking session expired. Start Google sign-in again.');
        }
        try {
            $user = DB::transaction(function () use ($r, $pending) {
                $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
                if (! $user || mb_strtolower($user->email) !== $pending['email'] || ! Hash::check($r->input('password'), $user->password)) {
                    throw ValidationException::withMessages(['password' => 'The password does not match your existing Vocamilo account.']);
                }
                if (($user->google_id && $user->google_id !== $pending['sub']) || User::where('google_id', $pending['sub'])->whereKeyNot($user->id)->exists()) {
                    throw ValidationException::withMessages(['password' => 'This Google identity is already linked. Please start again.']);
                }
                $user->google_id = $pending['sub'];
                if ($pending['authoritative'] && ! $user->email_verified_at) {
                    $user->email_verified_at = now();
                }
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->error('Your account changed during linking. Please start again.');
        }
        $r->session()->forget('google_link');

        return $this->signIn($r, $user);
    }

    public function cancel(Request $r)
    {
        $r->session()->forget('google_link');

        return redirect('/login');
    }

    private function pending(Request $r): ?array
    {
        $p = $r->session()->get('google_link');
        if (! is_array($p) || ($p['expires_at'] ?? 0) < time()) {
            $r->session()->forget('google_link');

            return null;
        }

        return $p;
    }

    private function signIn(Request $r, User $user)
    {
        Auth::login($user);
        $r->session()->forget('google_link');
        $r->session()->regenerate();

        return CollectionAccess::finishPendingJoin($r) ?? redirect('/dashboard');
    }

    private function error(string $message)
    {
        return redirect('/login')->withErrors(['google' => $message]);
    }
}
