<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutAddressChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your payout address was changed'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payout-address-changed');
    }
}
