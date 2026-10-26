<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Contracts\Entries\Entry;

/** Tells the customer, in their language, that their order was sent or is ready to pick up. */
class OrderShipped extends Mailable
{
    /**
     * @param  array{name: string, number: string, courier: string, tracking: ?string, place: ?string, url: string}  $details
     * @param  array{name: string, track_url: ?string, own: bool}|null  $courier
     */
    public function __construct(public Entry $order, public array $details, public bool $pickup, public ?array $courier) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __($this->pickup ? 'site.shop.mail_ready_subject' : 'site.shop.mail_sent_subject', $this->details));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-shipped');
    }
}
