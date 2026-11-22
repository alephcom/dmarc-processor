<?php

namespace App\Alerts;

/**
 * Alert types with their human labels and default thresholds (overridable per tenant).
 */
enum AlertType: string
{
    case NewSender = 'new_sender';
    case FailureSpike = 'failure_spike';
    case LegitFailing = 'legit_failing';
    case NoReports = 'no_reports';
    case DnsChanged = 'dns_changed';
    case SpfProblem = 'spf_problem';
    case IngestFailures = 'ingest_failures';

    public function label(): string
    {
        return match ($this) {
            self::NewSender => 'New unknown sender',
            self::FailureSpike => 'DMARC failures spiked',
            self::LegitFailing => 'Legitimate sender failing',
            self::NoReports => 'No reports received',
            self::DnsChanged => 'DMARC record changed',
            self::SpfProblem => 'SPF record broken',
            self::IngestFailures => 'Report processing failures',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NewSender => 'A server we haven\'t seen before starts sending as the domain.',
            self::FailureSpike => 'The share of mail passing DMARC drops sharply compared with the previous week.',
            self::LegitFailing => 'A sender marked legitimate starts failing DMARC (e.g. a DKIM key was removed).',
            self::NoReports => 'An active domain stops receiving reports (record removed, or reports going elsewhere).',
            self::DnsChanged => 'The DMARC record is removed, stops sending reports to us, or its policy is weakened.',
            self::SpfProblem => 'The SPF record becomes invalid, e.g. more than 10 DNS lookups.',
            self::IngestFailures => 'Incoming reports can\'t be processed or are quarantined (MSP only).',
        };
    }

    /** @return array<string, array{label: string, default: int|float, min: int, max: int, suffix?: string}> */
    public function thresholdFields(): array
    {
        return match ($this) {
            self::NewSender => ['min_daily_messages' => ['label' => 'Minimum messages per day', 'default' => 10, 'min' => 1, 'max' => 100000]],
            self::FailureSpike => [
                'drop_points' => ['label' => 'Drop in pass rate (percentage points)', 'default' => 10, 'min' => 1, 'max' => 100],
                'min_messages' => ['label' => 'Minimum messages per day', 'default' => 50, 'min' => 1, 'max' => 1000000],
            ],
            self::LegitFailing => [
                'min_pass_rate' => ['label' => 'Alert below pass rate (%)', 'default' => 95, 'min' => 1, 'max' => 100],
                'min_messages' => ['label' => 'Minimum messages over 2 days', 'default' => 20, 'min' => 1, 'max' => 1000000],
            ],
            self::NoReports => ['hours' => ['label' => 'Hours without a report', 'default' => 72, 'min' => 24, 'max' => 720]],
            self::IngestFailures => ['min_failures' => ['label' => 'Failures per hour', 'default' => 1, 'min' => 1, 'max' => 10000]],
            default => [],
        };
    }

    /** @return array<string, int|float> */
    public function defaults(): array
    {
        return array_map(fn ($f) => $f['default'], $this->thresholdFields());
    }

    public function mspOnly(): bool
    {
        return $this === self::IngestFailures;
    }

    /** @return list<self> */
    public static function forTenants(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => ! $t->mspOnly()));
    }

    /** @return array<string, string> */
    public static function options(bool $includeMsp = false): array
    {
        $out = [];
        foreach ($includeMsp ? self::cases() : self::forTenants() as $t) {
            $out[$t->value] = $t->label();
        }

        return $out;
    }
}
