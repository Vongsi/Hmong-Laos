<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Contracts\Entries\Entry;

/** Tells the shop team about a new order or a payment a customer reported. */
class OrderNotice extends Mailable
{
    public function __construct(public Entry $order, public string $subjectLine, public ?string $whatsappUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-notice');
    }
}
