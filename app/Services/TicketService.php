<?php

namespace App\Services;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Exceptions\UserFacingException;
use App\Mail\TicketReplyMail;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TicketService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function open(User $user, string $subject, string $body, TicketCategory $category, ?Order $order = null): Ticket
    {
        if ($order !== null && $order->buyer_id !== $user->id) {
            throw new UserFacingException('You can only open tickets about your own orders.');
        }

        return DB::transaction(function () use ($user, $subject, $body, $category, $order) {
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'order_id' => $order?->id,
                'subject' => $subject,
                'category' => $order !== null ? TicketCategory::OrderIssue : $category,
                'status' => TicketStatus::Open,
            ]);
            $ticket->messages()->create(['author_id' => $user->id, 'body' => $body]);
            $this->audit->log('ticket.opened', $ticket, ['order' => $order?->public_id], $user);

            return $ticket;
        });
    }

    public function reply(Ticket $ticket, User $author, string $body): void
    {
        if ($ticket->status === TicketStatus::Closed) {
            throw new UserFacingException("Ticket #{$ticket->id} is closed. Open a new ticket if you still need help.");
        }

        $staffReply = $author->can('tickets.manage') && $author->id !== $ticket->user_id;

        DB::transaction(function () use ($ticket, $author, $body, $staffReply) {
            $ticket->messages()->create(['author_id' => $author->id, 'body' => $body]);
            $ticket->status = $staffReply ? TicketStatus::Answered : TicketStatus::Open;
            $ticket->touch();
            $ticket->save();
        });

        if ($staffReply) {
            Mail::to($ticket->user)->queue((new TicketReplyMail($ticket))->afterCommit());
        }
    }

    public function close(Ticket $ticket, User $actor): void
    {
        if ($ticket->status === TicketStatus::Closed) {
            return;
        }
        $ticket->status = TicketStatus::Closed;
        $ticket->closed_at = now();
        $ticket->save();
        $this->audit->log('ticket.closed', $ticket, [], $actor);
    }
}
