<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use LogicException;

class InvalidOrderTransition extends LogicException
{
    public static function make(string $publicId, OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order {$publicId} cannot move from {$from->value} to {$to->value}.");
    }
}
