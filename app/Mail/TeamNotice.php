<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A short email to the shop team, with a link to act on it in the control panel. */
class TeamNotice extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(public string $subjectLine, public array $lines, public string $url, public string $urlLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.team-notice');
    }
}
