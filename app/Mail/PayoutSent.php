<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a seller, in their language, that the team sent them the money for their products. */
class PayoutSent extends Mailable
{
    /** @param  array{name: string, number: string, amount: string, date: string, ref: string, note: string, breakdown: string, url: string}  $details */
    public function __construct(public array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('site.payouts.mail_subject', $this->details));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payout-sent');
    }
}
