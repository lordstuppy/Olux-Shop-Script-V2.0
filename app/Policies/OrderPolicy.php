<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id || $user->can('orders.view');
    }

    /** Paying, cancelling and downloading are for the buyer only. */
    public function act(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id;
    }
}
