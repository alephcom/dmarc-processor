<?php

namespace App\Team;

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
        return new Content(markdown: 'mail.invitation', with: ['inv' => $this->invitation, 'url' => $this->url]);
    }
}
