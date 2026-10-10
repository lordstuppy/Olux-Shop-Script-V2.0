<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Services\UserService;
use App\Support\FormTrap;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $users->checkCredentials($data['email'], $data['password']);

        if ($user->hasTwoFactor()) {
            // Not signed in yet: the second factor is checked first.
            $request->session()->regenerate();
            $request->session()->put('login.two_factor', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires' => time() + 300,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        $users->completeLogin($user, $request->boolean('remember'), $request);
        // New session id after login prevents session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('account.orders'))->with('success', __('Signed in as :email.', ['email' => $user->email]));
    }

    public function showRegister(): View
    {
        return view('auth.register', ['formToken' => FormTrap::token()]);
    }

    public function register(Request $request, UserService $users): RedirectResponse
    {
        if (! FormTrap::passes($request)) {
            throw new UserFacingException(__('Registration could not be completed. Wait a few seconds, then submit the form again.'));
        }

        // Addresses are stored in lower case; compare them that way too.
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()],
            'accept_terms' => ['accepted'],
        ], [
            'email.unique' => __('An account with this email already exists. Sign in or reset your password.'),
            'accept_terms.accepted' => __('You must accept the terms of service and privacy policy to create an account.'),
        ]);

        try {
            $user = $users->register($data['name'], $data['email'], $data['password']);
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same address at the same moment.
            throw ValidationException::withMessages(['email' => __('An account with this email already exists. Sign in or reset your password.')]);
        }
        $users->completeLogin($user, false, $request);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')->with('success', __('Welcome, :name. We sent a confirmation link to :email.', ['name' => $user->name, 'email' => $user->email]));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', __('You have been signed out.'));
    }
}
