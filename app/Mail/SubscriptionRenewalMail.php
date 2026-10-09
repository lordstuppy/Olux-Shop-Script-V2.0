<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewalMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public OrderItem $item) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Your access to ":title" ends on :date', ['title' => $this->item->title, 'date' => $this->item->access_expires_at->format('Y-m-d')]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.subscription-renewal');
    }

    public static function templateKey(): string
    {
        return 'subscription_renewal';
    }

    public function templateData(): array
    {
        return [
            'product' => $this->item->title,
            'order_number' => $this->item->order->shortId(),
            'ends_at' => (string) $this->item->access_expires_at?->format('Y-m-d H:i'),
            'renew_url' => $this->item->product ? route('products.show', $this->item->product->slug) : route('products.index'),
        ];
    }
}
