<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
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

        $user = $users->login($data['email'], $data['password'], $request->boolean('remember'));
        // New session id after login prevents session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('account.orders'))->with('success', "Signed in as {$user->email}.");
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()],
            'accept_terms' => ['accepted'],
        ], [
            'email.unique' => 'An account with this email already exists. Sign in or reset your password.',
            'accept_terms.accepted' => 'You must accept the terms of service and privacy policy to create an account.',
        ]);

        $user = $users->register($data['name'], $data['email'], $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('products.index')->with('success', "Welcome, {$user->name}. Your account {$user->email} is ready.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been signed out.');
    }
}
