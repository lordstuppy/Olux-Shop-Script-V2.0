<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Payout;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayoutStatusMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Payout $payout) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Payout #:id is :status', ['id' => $this->payout->id, 'status' => strtolower($this->payout->status->label())]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.payout-status');
    }

    public static function templateKey(): string
    {
        return 'payout_status';
    }

    public function templateData(): array
    {
        return [
            'payout_number' => (string) $this->payout->id,
            'amount' => Money::format($this->payout->amount_minor, $this->payout->currency),
            'status' => mb_strtolower($this->payout->status->label()),
            'destination' => (string) $this->payout->destination,
            'reference' => (string) $this->payout->reference,
            'note' => (string) $this->payout->note,
            'payouts_url' => route('seller.payouts'),
        ];
    }
}
