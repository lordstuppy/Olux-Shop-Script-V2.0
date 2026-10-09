<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum TicketCategory: string
{
    use HasLabel;

    case Support = 'support';
    case OrderIssue = 'order_issue';
    case Seller = 'seller';
    case Billing = 'billing';
}
