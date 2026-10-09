<?php

namespace App\Enums;

enum TicketCategory: string
{
    case Support = 'support';
    case OrderIssue = 'order_issue';
    case Seller = 'seller';
    case Billing = 'billing';
}
