<?php

namespace App\Alerts\Delivery;

use App\Models\Alert;

final class WebhookPayload
{
    /** @return array<string, mixed> */
    public static function for(Alert $alert, string $event): array
    {
        return [
            'event' => 'alert.'.$event, // alert.opened | alert.resolved | test
            'sent_at' => now()->toIso8601String(),
            'alert' => [
                'id' => $alert->id,
                'type' => $alert->type,
                'severity' => $alert->severity,
                'title' => $alert->title,
                'body' => $alert->body,
                'domain' => $alert->domain?->name,
                'customer' => $alert->tenant?->name,
                'opened_at' => $alert->opened_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'resolution' => $alert->resolution,
                'url' => AlertLinks::url($alert),
                'data' => $alert->data,
            ],
        ];
    }

    /** "sha256=" + HMAC-SHA256(secret, timestamp + "." + body). Documented for receivers. */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
