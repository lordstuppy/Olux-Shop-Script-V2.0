<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $user->can('tickets.manage');
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
