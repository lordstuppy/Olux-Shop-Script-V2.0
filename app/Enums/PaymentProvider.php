<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PaymentProvider: string
{
    use HasLabel;

    case Shkeeper = 'shkeeper';
    case Balance = 'balance';
    case Manual = 'manual';
}
