<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin') or 'role:seller,admin'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if ($user === null || ! in_array($user->role->value, $roles, true)) {
            abort(403, 'Your account does not have access to this area.');
        }

        return $next($request);
    }
}
