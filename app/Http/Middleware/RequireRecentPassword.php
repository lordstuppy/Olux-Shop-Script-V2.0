<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like Laravel's password.confirm, but also safe on form submissions: an
 * unconfirmed POST returns the user to the form page after confirming,
 * instead of redirecting to the POST URL with GET.
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if (now()->getTimestamp() - $confirmedAt < (int) config('auth.password_timeout')) {
            return $next($request);
        }

        $return = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();
        $request->session()->put('url.intended', $return);

        return redirect()->route('password.confirm')
            ->with('info', __('Confirm your password to continue. This is required for sensitive actions.'));
    }
}
