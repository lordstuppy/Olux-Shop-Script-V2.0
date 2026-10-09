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

class PayoutAddressChangeMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile, public string $confirmUrl = '') {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Confirm your new payout address'));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.payout-address-change');
    }

    public static function templateKey(): string
    {
        return 'payout_address_change';
    }

    public function templateData(): array
    {
        return [
            'shop' => $this->profile->display_name,
            'new_address' => (string) $this->profile->pending_payout_address,
            'crypto' => (string) $this->profile->pending_payout_crypto,
            'confirm_url' => $this->confirmUrl,
            'account_url' => route('account.settings'),
        ];
    }
}
