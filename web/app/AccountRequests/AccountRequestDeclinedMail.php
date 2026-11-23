<?php

namespace App\AccountRequests;

use App\Models\AccountRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountRequestDeclinedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly AccountRequest $request, public readonly ?string $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' account request');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-request-declined', with: [
            'req' => $this->request,
            'note' => $this->note,
            'repoUrl' => config('dmarc.public_site.repo_url'),
        ]);
    }
}
