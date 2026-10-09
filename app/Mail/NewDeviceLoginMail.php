<?php

namespace App\Mail;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewDeviceLoginMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public UserDevice $device) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('New sign-in to your :app account', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.new-device-login');
    }
}
