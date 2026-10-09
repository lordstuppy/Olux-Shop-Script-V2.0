<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** $recipientRole is buyer, seller or staff; the text is written for that reader. */
class DisputeOpenedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Dispute $dispute, public string $recipientRole) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Dispute #:id opened on order :order', ['id' => $this->dispute->id, 'order' => $this->dispute->order->shortId()]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.dispute-opened');
    }

    public static function templateKey(): string
    {
        return 'dispute_opened';
    }

    public function templateData(): array
    {
        return [
            'dispute_number' => (string) $this->dispute->id,
            'order_number' => $this->dispute->order->shortId(),
            'product' => $this->dispute->item->title,
            'reason' => $this->dispute->reason->label(),
            'outcome' => $this->dispute->requested_outcome === 'refund' ? __('a refund') : __('a replacement'),
            'respond_by' => $this->dispute->seller_respond_by->format('Y-m-d H:i'),
            'dispute_url' => route('disputes.show', $this->dispute),
        ];
    }
}
