<?php

namespace App\Team;

use App\Models\AccountRequest;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly Invitation $invitation, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You\'re invited to '.$this->invitation->tenant->name.' on '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'inv' => $this->invitation,
            'url' => $this->url,
            // Organisations created from a public account request are on the free service (no tech support).
            'freeAccount' => AccountRequest::where('tenant_id', $this->invitation->tenant_id)->exists(),
            'repoUrl' => config('dmarc.public_site.repo_url'),
        ]);
    }
}
