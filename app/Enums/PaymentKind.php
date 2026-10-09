<?php

namespace App\Enums;

enum PaymentKind: string
{
    case Charge = 'charge';
    case Refund = 'refund';
}
