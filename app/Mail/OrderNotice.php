<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;

/** Tells the shop team about a new order or a payment a customer reported (with their receipt, if they added one). */
class OrderNotice extends Mailable
{
    public function __construct(public Entry $order, public string $subjectLine, public ?string $whatsappUrl = null, public ?Asset $receipt = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-notice');
    }

    public function attachments(): array
    {
        if (! $this->receipt) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->receipt->container()->diskHandle(), $this->receipt->path())
                ->as('receipt-'.$this->order->get('title').'.'.$this->receipt->extension())
                ->withMime((string) $this->receipt->mimeType()),
        ];
    }
}
