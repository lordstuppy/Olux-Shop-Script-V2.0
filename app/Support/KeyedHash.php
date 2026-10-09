<?php

namespace App\Support;

/**
 * HMAC-SHA256 keyed with the application key. Lookups also accept hashes
 * made with APP_PREVIOUS_KEYS, so rotating APP_KEY does not invalidate
 * stored gift card codes or recovery codes.
 */
final class KeyedHash
{
    public static function make(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** @return list<string> current hash first, then hashes under previous keys */
    public static function candidates(string $value): array
    {
        $keys = array_merge([(string) config('app.key')], array_filter((array) config('app.previous_keys', [])));

        return array_values(array_unique(array_map(fn (string $key) => hash_hmac('sha256', $value, $key), $keys)));
    }
}
