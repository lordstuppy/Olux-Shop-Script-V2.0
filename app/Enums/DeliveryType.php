<?php

namespace App\Enums;

enum DeliveryType: string
{
    case Instant = 'instant';
    case Manual = 'manual';
}
