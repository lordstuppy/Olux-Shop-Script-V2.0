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
    /** key => validation rules */
    public const EDITABLE = [
        'commission_bps' => ['integer', 'min:0', 'max:10000'],
        'payout_hold_days' => ['integer', 'min:0', 'max:90'],
        'min_payout_minor' => ['integer', 'min:100', 'max:10000000'],
        'order_ttl_minutes' => ['integer', 'min:10', 'max:1440'],
        'max_open_orders' => ['integer', 'min:1', 'max:20'],
        'max_downloads_per_item' => ['integer', 'min:1', 'max:1000'],
        'quote_ttl_minutes' => ['integer', 'min:5', 'max:120'],
        'renewal_reminder_days' => ['integer', 'min:1', 'max:60'],
        'payout_address_cooldown_hours' => ['integer', 'min:0', 'max:720'],
        'dispute_window_days' => ['integer', 'min:1', 'max:365'],
        'dispute_response_days' => ['integer', 'min:1', 'max:30'],
        'support_email' => ['email', 'max:255'],
    ];

    /**
     * Payment gateway settings, edited on /admin/gateway (super admin only).
     * Secrets (API keys, payout credentials) stay in the environment.
     */
    public const GATEWAY = [
        'payments_crypto_enabled' => ['boolean'],
        'payments_balance_enabled' => ['boolean'],
        'crypto_disabled' => ['nullable', 'string', 'max:255'],
        'order_min_minor' => ['integer', 'min:0', 'max:100000000'],
        'order_max_minor' => ['integer', 'min:0', 'max:1000000000'],
    ];

    private const CACHE_KEY = 'shop.settings';

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return self::EDITABLE + self::GATEWAY;
    }

    private static function cast(string $key, mixed $value): mixed
    {
        $rules = self::rules()[$key];

        return match (true) {
            in_array('integer', $rules, true) => (int) $value,
            in_array('boolean', $rules, true) => in_array((string) $value, ['1', 'true'], true),
            default => (string) $value,
        };
    }

    /**
     * Translated label and hint for each editable key.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function descriptions(): array
    {
        return [
            'commission_bps' => [__('Platform commission (basis points)'), __('1000 = 10.00%. Applies to new sales.')],
            'payout_hold_days' => [__('Payout hold (days)'), __('Earnings become payable after this many days.')],
            'min_payout_minor' => [__('Minimum payout (minor units)'), __('1000 = 10.00 in the payout currency.')],
            'order_ttl_minutes' => [__('Unpaid order lifetime (minutes)'), __('Stock is released when an unpaid order expires.')],
            'max_open_orders' => [__('Unpaid orders per buyer'), __('Prevents stock hoarding.')],
            'max_downloads_per_item' => [__('Downloads per purchased item'), __('Products may override this.')],
            'quote_ttl_minutes' => [__('Crypto quote validity (minutes)'), __('Older quotes must be refreshed before paying.')],
            'renewal_reminder_days' => [__('Subscription reminder (days before end)'), ''],
            'payout_address_cooldown_hours' => [__('Payout pause after address change (hours)'), ''],
            'dispute_window_days' => [__('Dispute window (days)'), __('How long after delivery a buyer can open a dispute.')],
            'dispute_response_days' => [__('Seller response time for disputes (days)'), __('After this the dispute goes to staff.')],
            'support_email' => [__('Support email address'), __('Shown in the footer and in emails.')],
        ];
    }

    /** Merges stored overrides into config. Never fails the request. */
    public static function apply(): void
    {
        try {
            $values = Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return;
        }
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::rules())) {
                config(['shop.'.$key => self::cast($key, $value)]);
            }
        }
    }

    /** @param array<string, scalar> $values */
    public static function save(array $values, User $actor): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::rules())) {
                continue;
            }
            $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $stored, 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);
        self::apply();
    }
}
