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
        return new Envelope(subject: __('Payout #:id is :status', ['id' => $this->payout->id, 'status' => strtolower($this->payout->status->label())]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payout-status');
    }
}
