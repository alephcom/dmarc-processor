<?php

namespace App\Alerts;

use App\Dns\Checks\CheckResult;
use App\Dns\Checks\Issue;
use App\Models\Alert;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\InboundMessage;
use App\Services\ScanResult;
use Illuminate\Support\Facades\DB;

/**
 * Detects alert conditions. Each check raises alerts that hold and resolves the ones that cleared.
 *
 * When checks run:
 *  - after a report import:  failure spike, legitimate sender failing, clears "no reports"
 *  - hourly:                 new senders, no reports, ingest failures (MSP), expiry
 *  - after a DNS check:      DMARC record changes, SPF problems
 */
class AlertEvaluator
{
    /** New domains see every sender for the first time; don't alert on those. */
    public const LEARNING_DAYS = 7;

    /** Event-style alerts (new sender, policy weakened) close themselves after this long. */
    public const EXPIRE_DAYS = 7;

    public function __construct(private readonly AlertManager $alerts, private readonly AlertSettings $settings) {}

    public function afterImport(Domain $domain): void
    {
        $this->failureSpike($domain);
        $this->legitFailing($domain);
        $this->alerts->resolveKey("no_reports:{$domain->id}");
    }

    public function hourly(): void
    {
        Domain::where('status', Domain::STATUS_ACTIVE)->with('tenant')->each(function (Domain $d) {
            $this->newSenders($d);
            $this->noReports($d);
        });
        $this->ingestFailures();
        $this->expire();
    }

    // ---- report-driven checks -------------------------------------------------------------

    public function newSenders(Domain $domain): void
    {
        if (! $this->settings->enabled($domain->tenant_id, AlertType::NewSender)
            || ! $domain->first_report_at || $domain->first_report_at->gt(now()->subDays(self::LEARNING_DAYS))) {
            return;
        }
        $min = $this->settings->threshold($domain->tenant_id, AlertType::NewSender, 'min_daily_messages');
        $since = now()->subDays(2)->toDateString();

        $sources = DomainSource::where('domain_id', $domain->id)
            ->where('classification', 'unknown')
            ->where('first_seen_at', '>=', $since)
            ->get();
        foreach ($sources as $src) {
            $row = DB::table('daily_source_stats')
                ->where('domain_id', $domain->id)->where('source_key', $src->source_key)->where('day', '>=', $since)
                ->selectRaw('MAX(total) AS peak, SUM(total) AS total, SUM(dmarc_pass) AS pass')->first();
            if ((int) $row->peak < $min) {
                continue;
            }
            $passing = (int) $row->total > 0 && (int) $row->pass === (int) $row->total;
            $this->alerts->raise(
                AlertType::NewSender,
                $passing ? Severity::INFO : Severity::WARNING,
                "new_sender:{$domain->id}:{$src->source_key}",
                "New sender for {$domain->name}: {$src->label}",
                ($passing
                    ? "{$src->label} started sending as {$domain->name} and passes DMARC, so it is probably a service someone set up. "
                    : "{$src->label} started sending as {$domain->name} and fails DMARC. If you don't recognise it, it may be spoofing. ")
                    .number_format((int) $row->total).' messages so far. Mark it legitimate or suspicious to close this alert.',
                $domain,
                data: ['source_key' => $src->source_key, 'messages' => (int) $row->total, 'passing' => $passing],
            );
        }
    }

    public function failureSpike(Domain $domain): void
    {
        $type = AlertType::FailureSpike;
        $key = "failure_spike:{$domain->id}";
        if (! $this->settings->enabled($domain->tenant_id, $type)) {
            return;
        }
        $t = $this->settings->for($domain->tenant_id, $type)['thresholds'];

        $latest = DB::table('daily_domain_stats')->where('domain_id', $domain->id)->max('day');
        if ($latest === null) {
            return;
        }
        $latest = substr((string) $latest, 0, 10);
        $day = DB::table('daily_domain_stats')->where('domain_id', $domain->id)->where('day', $latest)->first();
        $base = DB::table('daily_domain_stats')->where('domain_id', $domain->id)
            ->where('day', '<', $latest)->where('day', '>=', now()->parse($latest)->subDays(7)->toDateString())
            ->selectRaw('COALESCE(SUM(total),0) AS total, COALESCE(SUM(dmarc_pass),0) AS pass')->first();

        $dayTotal = (int) $day->total;
        $baseTotal = (int) $base->total;
        if ($dayTotal < $t['min_messages'] || $baseTotal < $t['min_messages']) {
            return;
        }
        $dayRate = $day->dmarc_pass / $dayTotal * 100;
        $baseRate = $base->pass / $baseTotal * 100;
        $drop = $baseRate - $dayRate;

        if ($drop >= $t['drop_points']) {
            $this->alerts->raise(
                $type,
                $drop >= 2 * $t['drop_points'] ? Severity::CRITICAL : Severity::WARNING,
                $key,
                "DMARC failures spiked for {$domain->name}",
                sprintf('On %s, %.1f%% of mail passed DMARC, down from %.1f%% over the previous week (%s messages). Check the sender list for a new or broken sender.', $latest, $dayRate, $baseRate, number_format($dayTotal)),
                $domain,
                data: ['day' => $latest, 'rate' => round($dayRate, 1), 'baseline' => round($baseRate, 1)],
            );
        } else {
            $this->alerts->resolveKey($key);
        }
    }

    public function legitFailing(Domain $domain): void
    {
        $type = AlertType::LegitFailing;
        if (! $this->settings->enabled($domain->tenant_id, $type)) {
            return;
        }
        $t = $this->settings->for($domain->tenant_id, $type)['thresholds'];
        $latest = DB::table('daily_domain_stats')->where('domain_id', $domain->id)->max('day');
        if ($latest === null) {
            return;
        }
        $since = now()->parse(substr((string) $latest, 0, 10))->subDay()->toDateString();

        $rows = DB::table('daily_source_stats AS s')
            ->join('domain_sources AS d', fn ($j) => $j->on('d.domain_id', '=', 's.domain_id')->on('d.source_key', '=', 's.source_key'))
            ->where('s.domain_id', $domain->id)->where('s.day', '>=', $since)->where('d.classification', 'legit')
            ->groupBy('s.source_key', 'd.label')
            ->selectRaw('s.source_key, d.label, SUM(s.total) AS total, SUM(s.dmarc_pass) AS pass')
            ->get();

        $active = [];
        foreach ($rows as $r) {
            $rate = $r->total > 0 ? $r->pass / $r->total * 100 : 100;
            if ((int) $r->total < $t['min_messages'] || $rate >= $t['min_pass_rate']) {
                continue;
            }
            $key = "legit_failing:{$domain->id}:{$r->source_key}";
            $active[] = $key;
            $this->alerts->raise(
                $type, Severity::CRITICAL, $key,
                "{$r->label} is failing DMARC for {$domain->name}",
                sprintf('%s is marked legitimate but only %s%% of its %s messages passed DMARC in the last two days. A DKIM key or SPF include may have been removed. Once your policy is quarantine or reject, this mail is being blocked.', $r->label, round($rate, 1) + 0, number_format((int) $r->total)),
                $domain,
                data: ['source_key' => $r->source_key, 'rate' => round($rate, 1)],
            );
        }
        $this->alerts->resolveCleared($type, $domain, $active);
    }

    public function noReports(Domain $domain): void
    {
        $type = AlertType::NoReports;
        $key = "no_reports:{$domain->id}";
        if (! $this->settings->enabled($domain->tenant_id, $type) || ! $domain->last_report_at) {
            return;
        }
        $hours = (int) $this->settings->threshold($domain->tenant_id, $type, 'hours');
        if ($domain->last_report_at->gt(now()->subHours($hours))) {
            $this->alerts->resolveKey($key);

            return;
        }
        $this->alerts->raise(
            $type, Severity::WARNING, $key,
            "No reports for {$domain->name} in {$hours} hours",
            'The last report arrived '.$domain->last_report_at->diffForHumans().'. Check that the DMARC record is still published and still includes our reporting address.',
            $domain,
        );
    }

    // ---- DNS-driven checks ------------------------------------------------------------------

    /**
     * @param  array{verified: bool, policy: ?string, pct: ?int}  $before  domain state before the scan
     */
    public function afterDnsScan(Domain $domain, array $before, ScanResult $result, bool $full): void
    {
        if ($result->dmarc->status !== CheckResult::UNKNOWN) {
            $this->dmarcChanges($domain, $before, $result);
        }
        if ($full && $result->spf->status !== CheckResult::UNKNOWN) {
            $this->spfProblem($domain, $result);
        }
    }

    private function dmarcChanges(Domain $domain, array $before, ScanResult $result): void
    {
        $type = AlertType::DnsChanged;
        $removedKey = "dns_changed:{$domain->id}:reporting";
        $nowVerified = $result->verified();

        if ($nowVerified) {
            $this->alerts->resolveKey($removedKey);
        } elseif ($before['verified'] && $this->settings->enabled($domain->tenant_id, $type)) {
            $missing = $result->dmarc->status === CheckResult::MISSING;
            $this->alerts->raise(
                $type, Severity::CRITICAL, $removedKey,
                $missing ? "DMARC record removed for {$domain->name}" : "{$domain->name} stopped sending reports to us",
                $missing
                    ? "There is no longer a DMARC record at _dmarc.{$domain->name}. The domain is unprotected and we will stop receiving reports. Re-publish it from the setup wizard."
                    : "The DMARC record for {$domain->name} changed and no longer includes our reporting address (or is now invalid). Re-publish the record from the setup wizard.",
                $domain,
                data: ['record' => $result->dmarc->raw],
            );
        }

        $rank = ['none' => 0, 'quarantine' => 1, 'reject' => 2];
        $newPolicy = $result->dmarc->parsed['policy'] ?? null;
        $newPct = $result->dmarc->parsed['pct'] ?? 100;
        if ($before['policy'] && $newPolicy && $this->settings->enabled($domain->tenant_id, $type)) {
            $weaker = $rank[$newPolicy] < $rank[$before['policy']]
                || ($newPolicy === $before['policy'] && $newPolicy !== 'none' && $newPct < ($before['pct'] ?? 100));
            if ($weaker) {
                $this->alerts->raise(
                    $type, Severity::WARNING, "dns_changed:{$domain->id}:policy",
                    "DMARC policy weakened for {$domain->name}",
                    sprintf('The policy changed from p=%s%s to p=%s%s. If this wasn\'t intentional, spoofed mail may now be delivered.',
                        $before['policy'], ($before['pct'] ?? 100) < 100 ? " pct={$before['pct']}" : '',
                        $newPolicy, $newPct < 100 ? " pct={$newPct}" : ''),
                    $domain,
                    data: ['from' => $before['policy'], 'to' => $newPolicy],
                );
            }
        }
    }

    private function spfProblem(Domain $domain, ScanResult $result): void
    {
        $key = "spf_problem:{$domain->id}";
        $serious = array_values(array_filter($result->spf->issues, fn ($i) => $i->severity === Issue::ERROR));
        // Only once setup is done; during onboarding the wizard already shows SPF problems.
        if ($serious === [] || ! $domain->dns_verified_at || ! $this->settings->enabled($domain->tenant_id, AlertType::SpfProblem)) {
            $this->alerts->resolveKey($key);

            return;
        }
        $this->alerts->raise(
            AlertType::SpfProblem, Severity::WARNING, $key,
            "SPF record broken for {$domain->name}",
            implode("\n", array_map(fn ($i) => $i->message.($i->fix ? ' '.$i->fix : ''), $serious)),
            $domain,
            data: ['codes' => array_map(fn ($i) => $i->code, $serious), 'record' => $result->spf->raw],
        );
    }

    // ---- MSP and housekeeping ---------------------------------------------------------------

    public function ingestFailures(): void
    {
        $type = AlertType::IngestFailures;
        if (! $this->settings->enabled(null, $type)) {
            return;
        }
        $count = InboundMessage::whereIn('status', [InboundMessage::STATUS_FAILED, InboundMessage::STATUS_QUARANTINED])
            ->where('created_at', '>=', now()->subHour())->count();
        if ($count >= $this->settings->threshold(null, $type, 'min_failures')) {
            $this->alerts->raise(
                $type, Severity::WARNING, 'ingest_failures',
                "{$count} incoming report(s) failed or were quarantined in the last hour",
                'Open Admin → Ingest queue to see the errors. Quarantined messages are reports sent to one domain\'s address about a different domain.',
                data: ['count' => $count],
            );
        } else {
            $this->alerts->resolveKey('ingest_failures');
        }
    }

    /** Close event-style alerts that have been open long enough, and new-sender alerts already acted on. */
    public function expire(): void
    {
        Alert::open()
            ->where(fn ($q) => $q->where('type', AlertType::NewSender->value)->orWhere('dedupe_key', 'like', 'dns_changed:%:policy'))
            ->where('opened_at', '<', now()->subDays(self::EXPIRE_DAYS))
            ->get()->each(fn (Alert $a) => $this->alerts->resolve($a, 'expired'));
    }
}
