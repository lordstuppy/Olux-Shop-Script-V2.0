<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PayoutStatus: string
{
    use HasLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    // Sent to Shkeeper; waiting for the payout callback or status poll.
    case Processing = 'processing';
    case Paid = 'paid';
    // Shkeeper reported a failed transfer; staff can resend or reject.
    case Failed = 'failed';
    case Rejected = 'rejected';
}
