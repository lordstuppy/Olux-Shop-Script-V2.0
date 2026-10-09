<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DisputeStatus: string
{
    use HasLabel;

    case AwaitingSeller = 'awaiting_seller';
    case AwaitingStaff = 'awaiting_staff';
    case Resolved = 'resolved';
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingSeller, self::AwaitingStaff], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::AwaitingSeller->value, self::AwaitingStaff->value];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::AwaitingSeller => 'Waiting for the seller',
            self::AwaitingStaff => 'Waiting for our team',
            self::Resolved => 'Resolved',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
