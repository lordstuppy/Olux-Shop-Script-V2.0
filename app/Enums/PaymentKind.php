<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PaymentKind: string
{
    use HasLabel;

    case Charge = 'charge';
    case Refund = 'refund';
}
