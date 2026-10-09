<?php

namespace App\Support;

/**
 * Translated labels for status-like database strings that are not enums.
 * Unknown values fall back to the raw value with underscores as spaces.
 */
class Labels
{
    public static function scanStatus(string $value): string
    {
        return match ($value) {
            'pending' => __('Pending'),
            'clean' => __('Clean'),
            'infected' => __('Infected'),
            'error' => __('Error'),
            'skipped' => __('Skipped'),
            default => self::fallback($value),
        };
    }

    public static function balanceType(string $value): string
    {
        return match ($value) {
            'gift_card' => __('gift card'),
            'refund' => __('refund'),
            'order_payment' => __('order payment'),
            'late_payment_credit' => __('late payment credit'),
            'overpayment_credit' => __('overpayment credit'),
            'admin_adjustment' => __('admin adjustment'),
            default => self::fallback($value),
        };
    }

    public static function rateSource(string $value): string
    {
        return match ($value) {
            'manual' => __('manual'),
            default => self::fallback($value),
        };
    }

    private static function fallback(string $value): string
    {
        return str_replace('_', ' ', $value);
    }
}
