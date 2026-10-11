<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Mail\Contracts\InAppNotice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the owner that a security setting of the account changed, so a takeover does not go unnoticed. */
class AccountSecurityMail extends Mailable implements InAppNotice, ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public const EVENTS = ['password_changed', 'password_reset', 'two_factor_enabled', 'two_factor_disabled', 'recovery_codes_regenerated', 'two_factor_locked'];

    public string $time;

    public string $summary;

    public function __construct(public User $user, public string $event)
    {
        $this->time = now()->format('Y-m-d H:i');
        $this->summary = $this->change();
    }

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Security change on your :app account', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.account-security');
    }

    public static function templateKey(): string
    {
        return 'account_security';
    }

    public function change(): string
    {
        return match ($this->event) {
            'password_changed' => __('Your password was changed.'),
            'password_reset' => __('Your password was reset with a link sent to this address.'),
            'two_factor_enabled' => __('Two-factor authentication was turned on.'),
            'two_factor_disabled' => __('Two-factor authentication was turned off.'),
            'recovery_codes_regenerated' => __('New two-factor recovery codes were created; the old ones no longer work.'),
            'two_factor_locked' => __('Too many wrong two-factor codes were entered after your password, so sign-in is paused for a while. Your password is known to whoever tried: change it now.'),
            default => __('A security setting of your account changed.'),
        };
    }

    public function templateData(): array
    {
        return [
            'email' => $this->user->email,
            'change' => $this->summary,
            'time' => $this->time,
            'account_url' => route('account.settings'),
        ];
    }

    public function inAppKind(): string
    {
        return 'account';
    }

    public function inAppUrl(): ?string
    {
        return route('account.settings');
    }
}
