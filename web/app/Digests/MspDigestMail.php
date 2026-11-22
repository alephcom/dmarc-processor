<?php

namespace App\Digests;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MspDigestMail extends Mailable
{
    use Queueable;

    /** @param array<string, mixed> $digest */
    public function __construct(public readonly array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'DMARC overview, all customers ('.$this->digest['period'].')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.digest-msp', with: ['d' => $this->digest, 'url' => url('/admin')]);
    }
}
