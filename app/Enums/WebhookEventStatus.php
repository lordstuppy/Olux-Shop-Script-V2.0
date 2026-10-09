<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum WebhookEventStatus: string
{
    use HasLabel;

    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Dead = 'dead';
}
