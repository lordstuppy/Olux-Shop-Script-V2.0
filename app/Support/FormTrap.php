<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bot protection that needs no JavaScript and no third party:
 *  - a honeypot field that people never see or fill in;
 *  - an encrypted render timestamp; forms submitted faster than a person
 *    could type, or from a page older than a day, are refused.
 */
final class FormTrap
{
    public const HONEYPOT = 'website';

    public const TOKEN = 'form_token';

    private const MIN_SECONDS = 3;

    private const MAX_SECONDS = 86400;

    public static function token(): string
    {
        return Crypt::encryptString((string) time());
    }

    public static function passes(Request $request): bool
    {
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            Log::notice('Form honeypot filled on {path} from ip {ip}', ['path' => $request->path(), 'ip' => $request->ip()]);

            return false;
        }

        try {
            $renderedAt = (int) Crypt::decryptString((string) $request->input(self::TOKEN, ''));
        } catch (Throwable) {
            return false;
        }

        $age = time() - $renderedAt;
        if ($age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
            Log::notice('Form submitted after {age}s on {path} from ip {ip}', ['age' => $age, 'path' => $request->path(), 'ip' => $request->ip()]);

            return false;
        }

        return true;
    }
}
