<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaidMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Order :order paid', ['order' => $this->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.order-paid');
    }

    public static function templateKey(): string
    {
        return 'order_paid';
    }

    public function templateData(): array
    {
        return [
            'order_number' => $this->order->shortId(),
            'total' => Money::format($this->order->total_minor, $this->order->currency),
            'items' => $this->order->items->map(fn ($i) => '- '.$i->title.' x '.$i->quantity.': '.($i->isDelivered() ? __('delivered') : __('waiting for delivery')))->implode("\n"),
            'order_url' => route('orders.show', $this->order),
        ];
    }
}
