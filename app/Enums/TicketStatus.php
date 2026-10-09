<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum TicketStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';
}
