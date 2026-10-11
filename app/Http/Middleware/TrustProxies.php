<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Reads trusted proxies from config at request time, so the setting survives
 * "php artisan config:cache" (env() is not available once config is cached).
 *
 * Only the client address, scheme and port are taken from a trusted proxy.
 * The host is never taken from X-Forwarded-Host (absolute URLs come from
 * APP_URL, see PinAppUrl), and with no proxies configured nobody is trusted.
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        $proxies = trim((string) config('shop.trusted_proxies', ''));
        if ($proxies === '') {
            return null;
        }

        return $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
    }

    /**
     * The framework trusts every proxy when none are configured but the Host
     * header ends in .on-forge.com or .on-vapor.com. The Host header comes from
     * the client, so that would let anyone choose their IP address with
     * X-Forwarded-For. Here an empty list always means: trust no proxy.
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        if ($this->proxies() === null) {
            return;
        }

        parent::setTrustedProxyIpAddresses($request);
    }
}
