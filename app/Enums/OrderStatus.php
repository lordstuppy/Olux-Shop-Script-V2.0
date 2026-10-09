<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OrderStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Paid = 'paid';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Allowed state transitions. Anything not listed is rejected by OrderService.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::Delivered, self::PartiallyRefunded, self::Refunded],
            self::Delivered => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Cancelled, self::Expired, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** True once money has been captured for the order. */
    public function isPaidState(): bool
    {
        return in_array($this, [self::Paid, self::Delivered, self::PartiallyRefunded, self::Refunded], true);
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }
}
