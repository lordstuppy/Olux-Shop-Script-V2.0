<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

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

        return is_string($value) && in_array($value, self::CHOICES, true) ? $value : 'system';
    }

    public static function cookie(string $theme): Cookie
    {
        return $theme === 'system'
            ? cookie()->forget(self::COOKIE)
            : cookie(self::COOKIE, $theme, 60 * 24 * 400);
    }
}
