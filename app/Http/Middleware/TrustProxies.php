<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Reads trusted proxies from config at request time, so the setting survives
 * "php artisan config:cache" (env() is not available once config is cached).
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $proxies = trim((string) config('shop.trusted_proxies', ''));
        if ($proxies === '') {
            return null;
        }

        return $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
    }
}
