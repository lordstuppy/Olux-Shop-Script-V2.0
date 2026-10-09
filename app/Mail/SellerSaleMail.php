<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerSaleMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Order $order, public User $seller) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('New sale: order :order', ['order' => $this->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.seller-sale');
    }

    public static function templateKey(): string
    {
        return 'seller_sale';
    }

    public function templateData(): array
    {
        return [
            'order_number' => $this->order->shortId(),
            'items' => $this->order->items->where('seller_id', $this->seller->id)
                ->map(fn ($i) => '- '.$i->title.' x '.$i->quantity.': '.Money::format($i->netMinor(), $this->order->currency))->implode("\n"),
            'sales_url' => route('seller.sales'),
            'dashboard_url' => route('seller.dashboard'),
        ];
    }
}
