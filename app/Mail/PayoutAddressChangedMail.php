<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutAddressChangedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Your payout address was changed'));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.payout-address-changed');
    }

    public static function templateKey(): string
    {
        return 'payout_address_changed';
    }

    public function templateData(): array
    {
        return [
            'shop' => $this->profile->display_name,
            'address' => (string) $this->profile->payout_address,
            'crypto' => (string) $this->profile->payout_crypto,
            'pause_hours' => (string) config('shop.payout_address_cooldown_hours'),
        ];
    }
}
