<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Exceptions\UserFacingException;
use App\Models\Coupon;
use App\Models\Order;
use App\Support\Money;

class CouponService
{
    /**
     * Read-only check used to preview a coupon on the checkout page.
     */
    public function preview(string $code, string $currency, int $subtotalMinor): array
    {
        $coupon = Coupon::query()->where('code', Coupon::normalizeCode($code))->first();
        if ($coupon === null) {
            throw new UserFacingException('Coupon code "'.Coupon::normalizeCode($code).'" was not found.');
        }
        $this->assertUsable($coupon, $currency, $subtotalMinor);

        return [$coupon, $this->discountFor($coupon, $subtotalMinor)];
    }

    /**
     * Locks the coupon row, re-validates it and reserves one redemption.
     * Must be called inside the order creation transaction.
     *
     * @return array{0: Coupon, 1: int} coupon and discount in minor units
     */
    public function reserve(string $code, string $currency, int $subtotalMinor): array
    {
        $coupon = Coupon::query()->where('code', Coupon::normalizeCode($code))->lockForUpdate()->first();
        if ($coupon === null) {
            throw new UserFacingException('Coupon code "'.Coupon::normalizeCode($code).'" was not found.');
        }
        $this->assertUsable($coupon, $currency, $subtotalMinor);

        $coupon->increment('redemptions_count');

        return [$coupon, $this->discountFor($coupon, $subtotalMinor)];
    }

    /** Gives back the redemption reserved by an order that will never be paid. */
    public function release(Order $order): void
    {
        if ($order->coupon_id === null) {
            return;
        }
        Coupon::query()->whereKey($order->coupon_id)->where('redemptions_count', '>', 0)->decrement('redemptions_count');
    }

    public function discountFor(Coupon $coupon, int $subtotalMinor): int
    {
        $discount = match ($coupon->type) {
            CouponType::Percent => Money::applyBps($subtotalMinor, $coupon->value),
            CouponType::Fixed => $coupon->value,
        };

        return min($discount, $subtotalMinor);
    }

    private function assertUsable(Coupon $coupon, string $currency, int $subtotalMinor): void
    {
        $code = $coupon->code;
        if (! $coupon->active) {
            throw new UserFacingException("Coupon {$code} is no longer active.");
        }
        if ($coupon->starts_at !== null && $coupon->starts_at->isFuture()) {
            throw new UserFacingException("Coupon {$code} is valid from {$coupon->starts_at->toDateString()}.");
        }
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            throw new UserFacingException("Coupon {$code} expired on {$coupon->expires_at->toDateString()}.");
        }
        if ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions) {
            throw new UserFacingException("Coupon {$code} has reached its redemption limit.");
        }
        // A coupon with a currency (always the case for fixed coupons) only
        // applies to orders in that currency.
        if ($coupon->currency !== null && $coupon->currency !== $currency) {
            throw new UserFacingException("Coupon {$code} applies only to orders in {$coupon->currency}; your cart is in {$currency}.");
        }
        // The minimum is expressed in minor units of the order currency.
        $minimum = $coupon->min_total_minor;
        if ($minimum > 0 && $subtotalMinor < $minimum) {
            throw new UserFacingException(sprintf(
                'Coupon %s requires a subtotal of at least %s; your subtotal is %s.',
                $code,
                Money::format($minimum, $currency),
                Money::format($subtotalMinor, $currency),
            ));
        }
    }
}
