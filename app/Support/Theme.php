<?php

namespace App\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Colour scheme preference. "system" follows the device setting
 * (prefers-color-scheme); "light" and "dark" override it.
 */
final class Theme
{
    public const CHOICES = ['system', 'light', 'dark'];

    private const COOKIE = 'theme';

    public static function current(Request $request): string
    {
        $value = $request->cookie(self::COOKIE);
        if (is_string($value) && ! in_array($value, self::CHOICES, true)) {
            // Error pages for unknown URLs render without the web middleware, so
            // the cookie is still encrypted; decrypt it the way EncryptCookies does.
            try {
                $encrypter = app('encrypter');
                $value = CookieValuePrefix::validate(self::COOKIE, $encrypter->decrypt($value, false), $encrypter->getAllKeys());
            } catch (Throwable) {
                $value = null;
            }
        }

        return is_string($value) && in_array($value, self::CHOICES, true) ? $value : 'system';
    }

    public static function cookie(string $theme): Cookie
    {
        return $theme === 'system'
            ? cookie()->forget(self::COOKIE)
            : cookie(self::COOKIE, $theme, 60 * 24 * 400);
    }
}
