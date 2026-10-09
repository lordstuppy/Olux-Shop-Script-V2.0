<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff (admin, finance, support) must have two-factor authentication on
 * before they can use the admin area.
 */
class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->isStaff() && config('shop.require_staff_two_factor') && ! $user->hasTwoFactor()) {
            return redirect()->route('account.two-factor')
                ->with('error', __('Staff accounts must turn on two-factor authentication before using the admin area.'));
        }

        return $next($request);
    }
}
