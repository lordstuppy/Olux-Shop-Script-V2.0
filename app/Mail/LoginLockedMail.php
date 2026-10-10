<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginLockedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public User $user, public int $attempts, public int $minutes) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Sign-in to your account was paused'));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.login-locked');
    }

    public static function templateKey(): string
    {
        return 'login_locked';
    }

    public function templateData(): array
    {
        return [
            'email' => $this->user->email,
            'attempts' => (string) $this->attempts,
            'minutes' => (string) $this->minutes,
            'reset_url' => route('password.request'),
        ];
    }
}
