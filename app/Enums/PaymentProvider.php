<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Shkeeper = 'shkeeper';
    case Balance = 'balance';
    case Manual = 'manual';
}
