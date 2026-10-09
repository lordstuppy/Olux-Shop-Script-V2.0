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

class EmailChangeNoticeMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public User $user, public string $newEmail) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Email change requested for your account'));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.email-change-notice');
    }

    public static function templateKey(): string
    {
        return 'email_change_notice';
    }

    public function templateData(): array
    {
        return [
            'new_email' => $this->newEmail,
            'account_url' => route('account.settings'),
        ];
    }
}
