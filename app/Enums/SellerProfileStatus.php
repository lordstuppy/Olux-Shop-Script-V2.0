<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum SellerProfileStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
