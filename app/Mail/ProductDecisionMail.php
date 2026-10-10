<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProductDecisionMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Product $product, public bool $approved, public string $note) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope($this->approved
            ? __('":title" is approved and for sale', ['title' => $this->product->title])
            : __('":title" is not for sale', ['title' => $this->product->title]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.product-decision');
    }

    public static function templateKey(): string
    {
        return 'product_decision';
    }

    public function templateData(): array
    {
        return [
            'product' => $this->product->title,
            'decision' => $this->approved ? __('approved and for sale') : __('not for sale'),
            'reason' => $this->note,
            'edit_url' => route('seller.products.edit', $this->product->id),
        ];
    }
}
