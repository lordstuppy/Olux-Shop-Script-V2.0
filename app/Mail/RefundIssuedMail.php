<?php

namespace App\Mail;

use App\Enums\PaymentProvider;
use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundIssuedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $refund) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Refund for order :order', ['order' => $this->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.refund-issued');
    }

    public static function templateKey(): string
    {
        return 'refund_issued';
    }

    public function templateData(): array
    {
        return [
            'order_number' => $this->order->shortId(),
            'amount' => Money::format($this->refund->amount_minor, $this->refund->currency),
            'method' => match ($this->refund->provider) {
                PaymentProvider::Balance => __('credited to your shop balance'),
                PaymentProvider::Shkeeper => __('sent in :crypto to :address', ['crypto' => $this->refund->crypto, 'address' => $this->refund->wallet_address]),
                default => __('paid back outside the shop (reference :reference)', ['reference' => $this->refund->provider_reference]),
            },
            'order_url' => route('orders.show', $this->order),
        ];
    }
}
