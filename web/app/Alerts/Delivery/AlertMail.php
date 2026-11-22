<?php

namespace App\Alerts\Delivery;

use App\Alerts\Severity;
use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    public function envelope(): Envelope
    {
        $prefix = $this->alert->severity === Severity::CRITICAL ? '[Critical] ' : '';

        return new Envelope(subject: $prefix.$this->alert->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert', with: [
            'alert' => $this->alert,
            'url' => AlertLinks::url($this->alert),
        ]);
    }
}
