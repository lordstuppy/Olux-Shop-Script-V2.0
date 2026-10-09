<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin-editable overrides for selected shop settings. Values are stored in
 * the settings table and merged into config('shop.*') at boot, so the rest
 * of the code keeps reading config(). Only the keys below are editable.
 */
final class Settings
{
    /** key => [label, validation rules, hint] */
    public const EDITABLE = [
        'commission_bps' => ['Platform commission (basis points)', ['integer', 'min:0', 'max:10000'], '1000 = 10.00%. Applies to new sales.'],
        'payout_hold_days' => ['Payout hold (days)', ['integer', 'min:0', 'max:90'], 'Earnings become payable after this many days.'],
        'min_payout_minor' => ['Minimum payout (minor units)', ['integer', 'min:100', 'max:10000000'], '1000 = 10.00 in the payout currency.'],
        'order_ttl_minutes' => ['Unpaid order lifetime (minutes)', ['integer', 'min:10', 'max:1440'], 'Stock is released when an unpaid order expires.'],
        'max_open_orders' => ['Unpaid orders per buyer', ['integer', 'min:1', 'max:20'], 'Prevents stock hoarding.'],
        'max_downloads_per_item' => ['Downloads per purchased item', ['integer', 'min:1', 'max:1000'], 'Products may override this.'],
        'quote_ttl_minutes' => ['Crypto quote validity (minutes)', ['integer', 'min:5', 'max:120'], 'Older quotes must be refreshed before paying.'],
        'renewal_reminder_days' => ['Subscription reminder (days before end)', ['integer', 'min:1', 'max:60'], ''],
        'payout_address_cooldown_hours' => ['Payout pause after address change (hours)', ['integer', 'min:0', 'max:720'], ''],
        'support_email' => ['Support email address', ['email', 'max:255'], 'Shown in the footer and in emails.'],
    ];

    private const CACHE_KEY = 'shop.settings';

    /** Merges stored overrides into config. Never fails the request. */
    public static function apply(): void
    {
        try {
            $values = Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return;
        }
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::EDITABLE)) {
                config(['shop.'.$key => in_array('integer', self::EDITABLE[$key][1], true) ? (int) $value : $value]);
            }
        }
    }

    /** @param array<string, scalar> $values */
    public static function save(array $values, User $actor): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::EDITABLE)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);
        self::apply();
    }
}
