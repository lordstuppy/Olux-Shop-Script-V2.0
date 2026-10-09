<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DeliveryType: string
{
    use HasLabel;

    case Instant = 'instant';
    case Manual = 'manual';
}
