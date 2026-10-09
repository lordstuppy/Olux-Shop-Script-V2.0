<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum CouponType: string
{
    use HasLabel;

    case Percent = 'percent';
    case Fixed = 'fixed';
}
