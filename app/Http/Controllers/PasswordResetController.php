<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function showRequest(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $users->sendPasswordResetLink($data['email']);
        $minutes = config('auth.passwords.users.expire');

        return back()->with('success', "If an account exists for {$data['email']}, a reset link has been sent. It expires in {$minutes} minutes.");
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()],
        ]);
        $users->resetPassword($data['email'], $data['token'], $data['password']);

        return redirect()->route('login')->with('success', 'Your password was changed and other sessions were signed out. Sign in with the new password.');
    }
}
