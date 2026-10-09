<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $newEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Email change requested for your account'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.email-change-notice');
    }
}
