<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('New reply on ticket #:id: :subject', ['id' => $this->ticket->id, 'subject' => $this->ticket->subject]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.ticket-reply');
    }

    public static function templateKey(): string
    {
        return 'ticket_reply';
    }

    public function templateData(): array
    {
        return [
            'ticket_number' => (string) $this->ticket->id,
            'subject' => $this->ticket->subject,
            'ticket_url' => route('tickets.show', $this->ticket),
        ];
    }
}
