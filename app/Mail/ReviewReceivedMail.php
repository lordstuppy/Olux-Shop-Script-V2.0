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

/** To the seller when a buyer reviews one of their products. */
class ReviewReceivedMail extends Mailable implements InAppNotice, ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public ProductReview $review) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('New :rating-star review of ":title"', ['rating' => $this->review->rating, 'title' => $this->review->product->title]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.review-received');
    }

    public static function templateKey(): string
    {
        return 'review_received';
    }

    public function templateData(): array
    {
        return [
            'product' => $this->review->product->title,
            'rating' => (string) $this->review->rating,
            'title' => $this->review->title,
            'body' => $this->review->body,
            'product_url' => route('products.show', $this->review->product->slug),
        ];
    }

    public function inAppKind(): string
    {
        return 'review';
    }

    public function inAppUrl(): ?string
    {
        return route('seller.reviews');
    }
}
