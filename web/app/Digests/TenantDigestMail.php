<?php

namespace App\Digests;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TenantDigestMail extends Mailable
{
    use Queueable;

    /** @param array<string, mixed> $digest */
    public function __construct(public readonly array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Email security summary for '.$this->digest['tenant']->name.' ('.$this->digest['period'].')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.digest-tenant', with: [
            'd' => $this->digest,
            'url' => url('/app/'.$this->digest['tenant']->slug),
        ]);
    }
}
