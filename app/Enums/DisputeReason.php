<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DisputeReason: string
{
    use HasLabel;

    case NotDelivered = 'not_delivered';
    case NotWorking = 'not_working';
    case NotAsDescribed = 'not_as_described';
    case Other = 'other';

    public function labelKey(): string
    {
        return match ($this) {
            self::NotDelivered => 'Item was never delivered',
            self::NotWorking => 'Key or file does not work',
            self::NotAsDescribed => 'Item is not as described',
            self::Other => 'Other problem',
        };
    }
}
