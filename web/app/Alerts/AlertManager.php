<?php

namespace App\Alerts;

use App\Jobs\DeliverAlert;
use App\Models\Alert;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Opens, refreshes and resolves alerts. Exactly one open alert exists per dedupe key; repeated
 * detections refresh it instead of notifying again. Notifications go out when an alert opens
 * (and to webhooks when it resolves).
 */
class AlertManager
{
    /** @param array<string, mixed> $data */
    public function raise(
        AlertType $type,
        string $severity,
        string $dedupeKey,
        string $title,
        ?string $body = null,
        ?Domain $domain = null,
        ?int $tenantId = null,
        array $data = [],
    ): Alert {
        $tenantId ??= $domain?->tenant_id;

        $alert = DB::transaction(function () use ($type, $severity, $dedupeKey, $title, $body, $domain, $tenantId, $data) {
            $open = Alert::open()->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
            if ($open) {
                $open->update([
                    'last_seen_at' => now(),
                    'body' => $body,
                    'data' => $data,
                    // Escalate but never downgrade while open.
                    'severity' => Severity::atLeast($severity, $open->severity) ? $severity : $open->severity,
                ]);

                return $open;
            }

            return Alert::create([
                'tenant_id' => $tenantId,
                'domain_id' => $domain?->id,
                'type' => $type->value,
                'severity' => $severity,
                'dedupe_key' => $dedupeKey,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'data' => $data,
                'opened_at' => now(),
                'last_seen_at' => now(),
            ]);
        });

        if ($alert->wasRecentlyCreated) {
            DeliverAlert::dispatch($alert->id, 'opened');
        }

        return $alert;
    }

    public function resolve(Alert $alert, string $resolution = 'auto', ?int $userId = null): void
    {
        if (! $alert->isOpen()) {
            return;
        }
        $alert->update(['resolved_at' => now(), 'resolution' => $resolution, 'resolved_by' => $userId]);
        DeliverAlert::dispatch($alert->id, 'resolved');
    }

    public function resolveKey(string $dedupeKey, string $resolution = 'auto'): void
    {
        Alert::open()->where('dedupe_key', $dedupeKey)->get()->each(fn (Alert $a) => $this->resolve($a, $resolution));
    }

    /**
     * Resolve open alerts of $type for a domain whose condition no longer holds.
     *
     * @param  list<string>  $stillActiveKeys
     */
    public function resolveCleared(AlertType $type, Domain $domain, array $stillActiveKeys): void
    {
        Alert::open()->where('type', $type->value)->where('domain_id', $domain->id)
            ->whereNotIn('dedupe_key', $stillActiveKeys ?: ['-'])
            ->get()->each(fn (Alert $a) => $this->resolve($a));
    }
}
