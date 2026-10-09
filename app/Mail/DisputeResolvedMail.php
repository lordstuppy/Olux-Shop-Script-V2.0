<?php

namespace App\Mail;

use App\Mail\Concerns\HasEditableTemplate;
use App\Models\Dispute;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** $recipientRole is buyer, seller or staff; the text is written for that reader. */
class DisputeResolvedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Dispute $dispute, public string $recipientRole) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Dispute #:id closed: :resolution', ['id' => $this->dispute->id, 'resolution' => mb_strtolower($this->dispute->resolution?->label() ?? '')]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.dispute-resolved');
    }

    public static function templateKey(): string
    {
        return 'dispute_resolved';
    }

    public function templateData(): array
    {
        return [
            'dispute_number' => (string) $this->dispute->id,
            'product' => $this->dispute->item->title,
            'outcome' => (string) $this->dispute->resolution?->label(),
            'refund' => $this->dispute->refund_minor ? Money::format($this->dispute->refund_minor, $this->dispute->order->currency) : '',
            'note' => (string) $this->dispute->resolution_note,
            'dispute_url' => route('disputes.show', $this->dispute),
        ];
    }
}
