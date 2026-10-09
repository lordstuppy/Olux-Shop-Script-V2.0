<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.orders');
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }

    /** The link in the email (signed, expiring, bound to the user id and email hash). */
    public function verify(EmailVerificationRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $audit->log('user.email_verified', $user, [], $user);
        }

        return redirect()->route('products.index')->with('success', "Email address {$user->email} confirmed. You can now check out.");
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.orders');
        }
        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', "A new confirmation link was sent to {$request->user()->email}.");
    }
}
