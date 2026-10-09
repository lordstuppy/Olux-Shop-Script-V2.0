<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Re-authentication before sensitive actions (refunds, payouts, role changes,
 * balance adjustments, settings, security settings).
 */
class ConfirmPasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        if (! Hash::check($data['password'], $request->user()->password_hash)) {
            throw new UserFacingException(__('The password is incorrect.'));
        }
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('account.settings'))
            ->with('success', __('Password confirmed for the next :minutes minutes. Submit the form again to continue.', ['minutes' => (int) (config('auth.password_timeout') / 60)]));
    }
}
