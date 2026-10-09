<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerApplicationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your seller application was '.$this->profile->status->value);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.seller-application');
    }
}
