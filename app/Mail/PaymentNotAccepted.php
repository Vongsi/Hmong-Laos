<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Contracts\Entries\Entry;

/** Tells the customer, in their language, that the team couldn't confirm their payment, and why. */
class PaymentNotAccepted extends Mailable
{
    /** @param  array{name: string, number: string, message: string, url: string}  $details */
    public function __construct(public Entry $order, public array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('site.shop.mail_rejected_subject', $this->details));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-not-accepted');
    }
}
