<?php

namespace App\Enums;

enum SellerProfileStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
