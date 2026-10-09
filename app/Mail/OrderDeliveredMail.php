<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDeliveredMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Order :order: an item was delivered', ['order' => $this->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.order-delivered');
    }

    public static function templateKey(): string
    {
        return 'order_delivered';
    }

    public function templateData(): array
    {
        return [
            'order_number' => $this->order->shortId(),
            'order_url' => route('orders.show', $this->order),
        ];
    }
}
