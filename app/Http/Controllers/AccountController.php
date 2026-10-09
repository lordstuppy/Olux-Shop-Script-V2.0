<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $current = $request->session()->getId();
        $sessions = config('session.driver') !== 'database' ? collect() : DB::table('sessions')
            ->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()
            ->map(fn ($row) => (object) [
                'handle' => SessionController::handle($row->id),
                'current' => $row->id === $current,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_activity' => $row->last_activity,
            ]);

        return view('account.settings', ['user' => $request->user(), 'sessions' => $sessions]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $request->user()->update(['name' => $data['name']]);

        return back()->with('success', __('Display name updated.'));
    }

    public function requestEmailChange(Request $request, UserService $users): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => __('The current password is incorrect.')]);
        $users->requestEmailChange($request->user(), $data['email']);

        return back()->with('success', __('We sent a confirmation link to :email. Your address changes once you open it (valid for 24 hours).', ['email' => $data['email']]));
    }

    public function showEmailConfirm(string $token): View
    {
        return view('account.email-confirm', ['token' => $token, 'pending' => auth()->user()->pending_email]);
    }

    public function confirmEmail(Request $request, string $token, UserService $users): RedirectResponse
    {
        $users->confirmEmailChange($request->user(), $token);

        return redirect()->route('account.settings')->with('success', __('Your email address is now :email.', ['email' => $request->user()->email]));
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()],
        ], ['current_password.current_password' => __('The current password is incorrect.')]);

        $user = $request->user();
        $user->forceFill(['password_hash' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        // Database sessions: drop every other session of this user.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $audit->log('user.password_changed', $user, [], $user);

        return back()->with('success', __('Password changed. Other sessions were signed out.'));
    }
}
