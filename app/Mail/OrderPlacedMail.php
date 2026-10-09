<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPlacedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order '.$this->order->shortId().' placed: awaiting payment');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.order-placed');
    }
}
