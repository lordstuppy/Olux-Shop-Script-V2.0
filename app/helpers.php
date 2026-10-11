<?php

use App\Support\Money;

if (! function_exists('money')) {
    /** Formats integer minor units for display: money(2500, 'EUR') === '25.00 EUR'. */
    function money(int $minor, string $currency): string
    {
        return Money::format($minor, $currency);
    }
}

if (! function_exists('static_asset')) {
    /**
     * URL of a file in public/ with a content hash in the query string, so the
     * web server can let browsers cache it for a year (docker/nginx/default.conf)
     * and a deploy that changes the file changes the URL.
     */
    function static_asset(string $path): string
    {
        static $hashes = [];
        $hashes[$path] ??= substr((string) @hash_file('xxh3', public_path($path)), 0, 12);

        return asset($path).($hashes[$path] !== '' ? '?v='.$hashes[$path] : '');
    }
}
