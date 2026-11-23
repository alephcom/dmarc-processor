<?php

namespace App\AccountRequests;

use App\Models\AccountRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells super-admins a new account request is waiting for review. */
class NewAccountRequestMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly AccountRequest $request, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New account request: '.$this->request->organisation);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-request', with: ['req' => $this->request, 'url' => $this->url]);
    }
}
