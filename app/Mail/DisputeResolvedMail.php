<?php

namespace App\Mail;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** $recipientRole is buyer, seller or staff; the text is written for that reader. */
class DisputeResolvedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Dispute $dispute, public string $recipientRole) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Dispute #:id closed: :resolution', ['id' => $this->dispute->id, 'resolution' => mb_strtolower($this->dispute->resolution?->label() ?? '')]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.dispute-resolved');
    }
}
