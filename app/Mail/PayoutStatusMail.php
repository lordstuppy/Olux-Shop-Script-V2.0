<?php

namespace App\Mail;

use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payout $payout) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payout #{$this->payout->id} is {$this->payout->status->value}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payout-status');
    }
}
