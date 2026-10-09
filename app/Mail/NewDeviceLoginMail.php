<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
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
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public User $user, public UserDevice $device) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('New sign-in to your :app account', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.new-device-login');
    }

    public static function templateKey(): string
    {
        return 'new_device_login';
    }

    public function templateData(): array
    {
        return [
            'email' => $this->user->email,
            'time' => $this->device->created_at->format('Y-m-d H:i'),
            'ip' => (string) $this->device->ip,
            'browser' => (string) $this->device->user_agent,
            'account_url' => route('account.settings'),
        ];
    }
}
