<?php

namespace App\Mail;

use App\Enums\SellerProfileStatus;
use App\Mail\Concerns\HasEditableTemplate;
use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerApplicationMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public SellerProfile $profile) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Your seller application was :status', ['status' => strtolower($this->profile->status->label())]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.seller-application');
    }

    public static function templateKey(): string
    {
        return 'seller_application';
    }

    public function templateData(): array
    {
        return [
            'shop' => $this->profile->display_name,
            'decision' => $this->profile->status === SellerProfileStatus::Approved ? __('approved') : __('not approved'),
            'reason' => (string) $this->profile->review_note,
            'next_url' => $this->profile->status === SellerProfileStatus::Approved ? route('seller.products.create') : route('seller.apply'),
        ];
    }
}
