<?php

namespace App\Mail;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public OrderItem $item) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your access to \"{$this->item->title}\" ends on {$this->item->access_expires_at->format('Y-m-d')}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.subscription-renewal');
    }
}
