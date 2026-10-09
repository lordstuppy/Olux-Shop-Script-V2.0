<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DisputeResolution: string
{
    use HasLabel;

    case Refund = 'refund';
    case Replacement = 'replacement';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function labelKey(): string
    {
        return match ($this) {
            self::Refund => 'Refunded',
            self::Replacement => 'Replacement delivered',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn by the buyer',
        };
    }
}
