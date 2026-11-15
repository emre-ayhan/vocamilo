<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CollectionAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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
        $r->session()->forget(['google_link', 'google_oauth', 'state', 'code_verifier']);
        $remember = $r->isMethod('post') ? (bool) ($r->validate(['remember' => 'sometimes|boolean'])['remember'] ?? false) : false;
        if (! self::enabled()) {
            return $this->error('Google sign-in is not configured yet.');
        }

        try {
            $response = Socialite::driver('google')->enablePKCE()->scopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])->redirect();
            $r->session()->put('google_oauth', ['remember' => $remember, 'state' => $r->session()->get('state'), 'expires_at' => time() + 600]);

            return $r->header('X-Inertia') ? Inertia::location($response->getTargetUrl()) : $response;
        } catch (\Throwable $e) {
            return $this->error('Google sign-in could not be completed. Please try again.');
        }
    }

    public function callback(Request $r)
    {
        $r->session()->forget('google_link');
        $flow = $r->session()->pull('google_oauth');
        $remember = false;
        if (is_array($flow)) {
            if (! is_int($flow['expires_at'] ?? null) || $flow['expires_at'] <= time()) {
                return $this->error('This sign-in session expired. Please start again.');
            }
            // Remember is tied to the same server-side OAuth state, never callback input.
            $expected = $r->session()->get('state');
            if (! is_string($flow['state'] ?? null) || ! is_string($expected) || ! is_string($r->query('state')) || ! hash_equals($flow['state'], $expected) || ! hash_equals($expected, $r->query('state'))) {
                return $this->error('This sign-in session expired. Please start again.');
            }
            $remember = ($flow['remember'] ?? false) === true;
        }
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
        $identity = ['sub' => $sub, 'email' => $email, 'name' => mb_substr(trim((string) $google->getName()) ?: 'Learner', 0, 100), 'authoritative' => $authoritative, 'remember' => $remember, 'expires_at' => time() + 600];
        $linked = User::where('google_id', $sub)->first();
        if ($linked) {
            return $this->signIn($r, $linked, $remember);
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

        return $this->signIn($r, $user, $remember);
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
        $pending = $this->pending($r);
        $r->validate(['password' => 'required|string']);
        if (! $pending) {
            return $this->error('The account-linking session expired. Start Google sign-in again.');
        }
        try {
            $user = DB::transaction(function () use ($r, $pending) {
                $user = User::whereKey($pending['user_id'])->lockForUpdate()->first();
                if (! $user || mb_strtolower($user->email) !== $pending['email']) {
                    $r->session()->forget('google_link');
                    throw ValidationException::withMessages(['password' => 'Your account changed during linking. Please start again.']);
                }
                if (! Hash::check($r->input('password'), $user->password)) {
                    throw ValidationException::withMessages(['password' => 'The password does not match your existing Vocamilo account.']);
                }
                if (($user->google_id && $user->google_id !== $pending['sub']) || User::where('google_id', $pending['sub'])->whereKeyNot($user->id)->exists()) {
                    $r->session()->forget('google_link');
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

        return $this->signIn($r, $user, ($pending['remember'] ?? false) === true);
    }

    public function cancel(Request $r)
    {
        $r->session()->forget(['google_link', 'google_oauth', 'state', 'code_verifier']);

        return redirect('/login');
    }

    private function pending(Request $r): ?array
    {
        $p = $r->session()->get('google_link');
        if (! is_array($p) || ($p['expires_at'] ?? 0) <= time()) {
            $r->session()->forget('google_link');

            return null;
        }

        return $p;
    }

    private function signIn(Request $r, User $user, bool $remember = false)
    {
        if ($user->suspended_at) {
            $r->session()->forget(['pending_collection_uuid', 'pending_collection_progress_consent', 'pending_collection_consent_expires_at', 'pending_collection_consent_version']);
            Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));

            return $this->error('This account is suspended.');
        }
        if (! $remember) {
            Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
        }
        Auth::login($user, $remember);
        $r->session()->forget(['google_link', 'google_oauth', 'state', 'code_verifier']);
        $r->session()->regenerate();

        return CollectionAccess::finishPendingJoin($r) ?? redirect()->intended('/dashboard');
    }

    private function error(string $message)
    {
        request()->session()->forget(['google_link', 'google_oauth', 'state', 'code_verifier']);

        return redirect('/login')->withErrors(['google' => $message]);
    }
}
