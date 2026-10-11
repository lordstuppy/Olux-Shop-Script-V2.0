<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Internal alert for the staff address; not editable on the email templates page. */
class StaffAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @param  list<string>  $lines */
    public function __construct(public string $alertSubject, public array $lines, public ?string $url = null) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] '.$this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.staff-alert');
    }
}
