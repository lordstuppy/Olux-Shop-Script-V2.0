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
    // Taken off sale by the seller; the seller can put it back on sale.
    case Paused = 'paused';
}
