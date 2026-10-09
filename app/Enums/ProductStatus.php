<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ProductStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Disabled = 'disabled';
}
