<?php

namespace App\Mail;

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

class OrderPlacedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $payment) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Order :order placed: awaiting payment', ['order' => $this->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.order-placed');
    }

    public static function templateKey(): string
    {
        return 'order_placed';
    }

    public function templateData(): array
    {
        return [
            'order_number' => $this->order->shortId(),
            'total' => Money::format($this->order->total_minor, $this->order->currency),
            'crypto' => (string) $this->payment->crypto,
            'pay_url' => route('orders.pay', $this->order),
            'expires_at' => (string) $this->order->expires_at?->format('Y-m-d H:i'),
        ];
    }
}
