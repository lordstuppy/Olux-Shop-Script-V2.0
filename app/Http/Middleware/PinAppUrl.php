<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds every absolute URL from APP_URL instead of the request's Host
 * header. Without this a client can send "Host: evil.example" and have the
 * shop put that host into links it hands to others: the Shkeeper callback
 * URL (Shkeeper would then post the payment callback, with its API key, to
 * the attacker) and the payout-address confirmation email.
 *
 * On by default outside local development and tests (shop.pin_app_url).
 */
class PinAppUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = rtrim((string) config('app.url'), '/');
        if (config('shop.pin_app_url') && $root !== '') {
            URL::forceRootUrl($root);
            if (str_starts_with($root, 'https://')) {
                URL::forceScheme('https');
            }
        }

        return $next($request);
    }
}
