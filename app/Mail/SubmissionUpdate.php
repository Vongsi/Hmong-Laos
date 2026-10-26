<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a member, in their language, that what they sent is on the website, or what to change.
 * $group is the set of texts to use: "sell" for products, "contribute" for news, events and stories.
 */
class SubmissionUpdate extends Mailable
{
    public function __construct(public string $group, public string $title, public bool $live, public ?string $note, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("site.{$this->group}.".($this->live ? 'mail_live_subject' : 'mail_changes_subject'), ['product' => $this->title, 'title' => $this->title]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.submission-update');
    }
}
