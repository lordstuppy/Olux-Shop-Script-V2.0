<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Mail\Contracts\InAppNotice;
use App\Models\ProductReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the reviewer when the seller answers their review. */
class ReviewReplyMail extends Mailable implements InAppNotice, ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public ProductReview $review) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('The seller replied to your review of ":title"', ['title' => $this->review->product->title]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.review-reply');
    }

    public static function templateKey(): string
    {
        return 'review_reply';
    }

    public function templateData(): array
    {
        return [
            'product' => $this->review->product->title,
            'reply' => (string) $this->review->seller_reply,
            'review_url' => $this->reviewUrl(),
        ];
    }

    public function inAppKind(): string
    {
        return 'review';
    }

    public function inAppUrl(): ?string
    {
        return $this->reviewUrl();
    }

    private function reviewUrl(): string
    {
        return route('products.show', $this->review->product->slug).'#review-'.$this->review->id;
    }
}
