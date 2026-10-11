<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records refused requests for incident investigation: access denied (403),
 * expired or missing CSRF token (419) and rate limits (429). The route name
 * is logged instead of the path, because some paths carry secrets (password
 * reset and confirmation tokens). Webhook signature failures and failed
 * logins are logged where they are detected.
 */
class LogSecurityEvents
{
    private const STATUSES = [403 => 'access denied', 419 => 'csrf token rejected', 429 => 'rate limit hit'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $status = $response->getStatusCode();
        if (isset(self::STATUSES[$status])) {
            Log::notice('Security event: {event} on {method} {route}', [
                'event' => self::STATUSES[$status],
                'status' => $status,
                'method' => $request->method(),
                'route' => $this->routeLabel($request),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    /** Route name, or the route pattern (e.g. "reset-password/{token}") for unnamed routes: never the actual values. */
    private function routeLabel(Request $request): string
    {
        $route = $request->route();
        if ($route === null) {
            return 'none';
        }
        $name = (string) $route->getName();

        return $name !== '' && ! str_starts_with($name, 'generated::') ? $name : '/'.ltrim($route->uri(), '/');
    }
}
