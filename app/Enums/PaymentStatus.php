<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
