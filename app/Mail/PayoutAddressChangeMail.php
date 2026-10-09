<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutAddressChangeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile, public string $confirmUrl = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm your new payout address'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payout-address-change');
    }
}
