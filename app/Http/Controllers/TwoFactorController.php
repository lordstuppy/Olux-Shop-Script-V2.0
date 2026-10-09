<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    private const SETUP_KEY = 'two_factor.setup_secret';

    /** Step two of signing in. */
    public function challenge(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login')->with('error', __('Your sign-in attempt expired. Enter your email and password again.'));
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request, TwoFactorService $twoFactor, UserService $users): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login')->with('error', __('Your sign-in attempt expired. Enter your email and password again.'));
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new UserFacingException(__('Too many incorrect codes. Wait :seconds seconds and try again.', ['seconds' => RateLimiter::availableIn($key)]));
        }
        if (! $twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 60);
            Log::notice('Invalid two-factor code for user {user_id}', ['user_id' => $user->id]);
            throw new UserFacingException(__('That code is not valid. Enter the current 6-digit code from your authenticator app, or a recovery code.'));
        }
        RateLimiter::clear($key);

        $pending = $request->session()->pull('login.two_factor');
        $users->completeLogin($user, (bool) ($pending['remember'] ?? false), $request);
        $request->session()->regenerate();

        return redirect()->intended(route('account.orders'))->with('success', __('Signed in as :email.', ['email' => $user->email]));
    }

    /** Account page section for setting up or managing 2FA. */
    public function show(Request $request, TwoFactorService $twoFactor): View
    {
        $user = $request->user();
        $secret = null;
        $qr = null;
        if (! $user->hasTwoFactor()) {
            $secret = $request->session()->get(self::SETUP_KEY) ?? $twoFactor->generateSecret();
            $request->session()->put(self::SETUP_KEY, $secret);
            $qr = $twoFactor->qrDataUri($user, $secret);
        }

        return view('account.two-factor', [
            'user' => $user,
            'secret' => $secret,
            'qr' => $qr,
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'),
            'remaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function enable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $secret = $request->session()->get(self::SETUP_KEY);
        if (! is_string($secret)) {
            throw new UserFacingException(__('The setup expired. Scan the new QR code and try again.'));
        }

        $codes = $twoFactor->enable($request->user(), $secret, $data['code']);
        $request->session()->forget(self::SETUP_KEY);

        return redirect()->route('account.two-factor')
            ->with('two_factor.recovery_codes', $codes)
            ->with('success', __('Two-factor authentication is on. Store the recovery codes below somewhere safe; they are shown only once.'));
    }

    public function disable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            throw new UserFacingException(__('Staff accounts must keep two-factor authentication on.'));
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $twoFactor->disable($user, $data['code']);

        return redirect()->route('account.two-factor')->with('success', __('Two-factor authentication is off.'));
    }

    public function regenerate(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $codes = $twoFactor->regenerateRecoveryCodes($request->user());

        return redirect()->route('account.two-factor')
            ->with('two_factor.recovery_codes', $codes)
            ->with('success', __('New recovery codes created. The old ones no longer work.'));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('login.two_factor');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            return null;
        }
        $user = User::find($pending['id'] ?? 0);

        return $user !== null && $user->isActive() && $user->hasTwoFactor() ? $user : null;
    }
}
