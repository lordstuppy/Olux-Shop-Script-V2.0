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
class DisputeEscalatedMail extends Mailable implements ShouldQueue
{
    use HasEditableTemplate, Queueable, SerializesModels;

    public function __construct(public Dispute $dispute, public string $recipientRole) {}

    public function envelope(): Envelope
    {
        return $this->templatedEnvelope(__('Dispute #:id is now with our team', ['id' => $this->dispute->id]));
    }

    public function content(): Content
    {
        return $this->templatedContent('mail.dispute-escalated');
    }

    public static function templateKey(): string
    {
        return 'dispute_escalated';
    }

    public function templateData(): array
    {
        return [
            'dispute_number' => (string) $this->dispute->id,
            'product' => $this->dispute->item->title,
            'dispute_url' => route('disputes.show', $this->dispute),
        ];
    }
}
