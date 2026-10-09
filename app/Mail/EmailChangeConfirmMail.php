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

class EmailChangeConfirmMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public User $user, public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Confirm your new email address'));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.email-change-confirm');
    }

    public static function templateKey(): string
    {
        return 'email_change_confirm';
    }

    public function templateData(): array
    {
        return [
            'current_email' => $this->user->email,
            'confirm_url' => $this->confirmUrl,
        ];
    }
}
