<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Dead = 'dead';
}
