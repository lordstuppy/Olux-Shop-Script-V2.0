<?php

namespace App\Support;

use App\Exceptions\UserFacingException;
use App\Models\Order;
use App\Models\User;

/**
 * Staff may also buy and sell. Money and delivery actions on their own orders,
 * sales, payouts or balance must be done by another team member, so no single
 * person can pay themselves.
 */
final class SelfDealing
{
    public static function assertNotSelf(User $staff, ?int ...$partyIds): void
    {
        if (in_array($staff->id, array_filter($partyIds), true)) {
            throw new UserFacingException(__('This concerns your own account, order, sale or payout. Another team member has to do it.'));
        }
    }

    /** The buyer and every seller of the order. */
    public static function assertNotPartyToOrder(User $staff, Order $order): void
    {
        self::assertNotSelf($staff, $order->buyer_id, ...$order->items()->pluck('seller_id')->map(fn ($id) => (int) $id)->all());
    }
}
