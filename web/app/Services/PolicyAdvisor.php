<?php

namespace App\Services;

use App\Dns\Checks\DmarcRecord;
use App\Dns\Checks\DmarcRecordBuilder;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Recommends when a domain can safely move to a stricter DMARC policy, and why (not).
 *
 * Ladder: none → quarantine 25% → 50% → 100% → reject (stops at quarantine 100% if that's the target).
 * A step is recommended only when enough time has passed at the current one and, over the last
 * 14 days, legitimate senders pass DMARC and unclassified senders are a negligible share.
 */
class PolicyAdvisor
{
    public const LADDER = [['none', 100], ['quarantine', 25], ['quarantine', 50], ['quarantine', 100], ['reject', 100]];

    public const WINDOW_DAYS = 14;

    public const MIN_LEGIT_PASS = 98.0;

    public const MAX_UNCLASSIFIED_SHARE = 1.0;

    public function advise(Domain $domain): PolicyAdvice
    {
        $current = [$domain->current_policy ?? 'none', (int) ($domain->current_pct ?? 100)];
        $label = self::label(...$current);

        if (! $domain->dns_verified_at) {
            return new PolicyAdvice('setup', $label, null, 'Finish setup first', 'Publish the DMARC record from the setup wizard so we start receiving reports.');
        }

        $next = $this->nextStep($current, $domain->target_policy);
        if ($next === null) {
            return new PolicyAdvice('done', $label, null, "You're at your target policy ({$label})",
                'Spoofed mail using this domain is '.($current[0] === 'reject' ? 'refused' : 'sent to spam').'. Keep an eye on alerts for senders that start failing.');
        }

        $checks = [];
        $window = DomainStats::from(self::WINDOW_DAYS);

        // Time at the current step.
        $required = match (true) {
            $current[0] === 'none' => 30,
            $next[0] === 'reject' => 30,
            default => 14,
        };
        $since = $current[0] === 'none' ? $domain->first_report_at : ($domain->policy_changed_at ?? $domain->first_report_at);
        $days = $since ? (int) $since->diffInDays(now()) : 0;
        $timeOk = $days >= $required;
        $checks[] = [$timeOk, $current[0] === 'none'
            ? ($timeOk ? "{$days} days of reports collected (30 needed)" : "{$days} of 30 days of reports collected")
            : ($timeOk ? "{$days} days at {$label} ({$required} needed)" : "{$days} of {$required} days at {$label}")];

        // Recent data.
        $recent = DB::table('daily_domain_stats')->where('domain_id', $domain->id)->where('day', '>=', DomainStats::from(7))->sum('total');
        $checks[] = [$recent > 0, $recent > 0 ? 'Reports received in the last 7 days' : 'No reports in the last 7 days'];

        // Sender health over the window.
        $rows = DB::table('daily_source_stats AS s')
            ->leftJoin('domain_sources AS d', fn ($j) => $j->on('d.domain_id', '=', 's.domain_id')->on('d.source_key', '=', 's.source_key'))
            ->where('s.domain_id', $domain->id)->where('s.day', '>=', $window)
            ->groupBy('d.classification')
            ->selectRaw("COALESCE(d.classification, 'unknown') AS c, SUM(s.total) AS total, SUM(s.dmarc_pass) AS pass")
            ->get()->keyBy('c');
        $all = (int) $rows->sum('total');
        $legit = $rows->get('legit');
        $legitTotal = (int) ($legit->total ?? 0);
        $legitRate = $legitTotal > 0 ? round($legit->pass / $legitTotal * 100, 1) : null;
        $unclassified = (int) ($rows->get('unknown')->total ?? 0);
        $unclassifiedShare = $all > 0 ? round($unclassified / $all * 100, 1) : 0.0;

        $checks[] = $legitTotal === 0
            ? [false, 'No senders marked legitimate yet: mark the services you use in the sender list']
            : [$legitRate >= self::MIN_LEGIT_PASS, "Legitimate senders pass DMARC {$legitRate}% of the time (".self::MIN_LEGIT_PASS.'% needed)'];
        $checks[] = [$unclassifiedShare <= self::MAX_UNCLASSIFIED_SHARE, $unclassifiedShare <= self::MAX_UNCLASSIFIED_SHARE
            ? "Unclassified senders: {$unclassifiedShare}% of mail"
            : "{$unclassifiedShare}% of mail comes from senders not yet marked legitimate or suspicious (at most ".self::MAX_UNCLASSIFIED_SHARE.'%)'];

        $healthOk = $checks[1][0] && $checks[2][0] && $checks[3][0];
        $nextLabel = self::label(...$next);
        $record = (new DmarcRecordBuilder)->build($this->existingRecord($domain), $domain->ruaAddress(), $next[0], $next[1]);

        if (! $healthOk) {
            $enforcing = $current[0] !== 'none';

            return new PolicyAdvice('blocked', $label, $next, 'Not ready for '.$nextLabel.' yet',
                $enforcing
                    ? 'Some legitimate mail may currently be quarantined. Fix the items below before tightening further.'
                    : 'Moving now could send legitimate mail to spam. Fix the items below first.',
                $checks);
        }
        if (! $timeOk) {
            return new PolicyAdvice('wait', $label, $next, 'On track for '.$nextLabel,
                'Everything looks healthy. We recommend waiting a little longer so rarely-used senders (monthly invoices, quarterly newsletters) show up in the reports.',
                $checks);
        }

        return new PolicyAdvice('ready', $label, $next, "Ready to move to {$nextLabel}",
            $next[0] === 'reject'
                ? 'All your legitimate mail is passing. Publishing this record tells receivers to refuse mail that fails DMARC.'
                : 'All your legitimate mail is passing. Publishing this record sends '.($next[1] < 100 ? "{$next[1]}% of " : '').'mail that fails DMARC to spam.',
            $checks, $record);
    }

    /** @return array{0: string, 1: int}|null */
    private function nextStep(array $current, string $target): ?array
    {
        $rank = fn (array $s) => ['none' => 0, 'quarantine' => 1, 'reject' => 2][$s[0]] * 1000 + ($s[0] === 'none' ? 0 : $s[1]);
        foreach (self::LADDER as $step) {
            if ($target === 'quarantine' && $step[0] === 'reject') {
                return null;
            }
            if ($rank($step) > $rank($current)) {
                return $step;
            }
        }

        return null;
    }

    private function existingRecord(Domain $domain): ?DmarcRecord
    {
        $raw = $domain->dnsChecks()->where('type', 'dmarc')->latest('checked_at')->latest('id')->value('raw');

        return $raw && ! str_contains($raw, "\n") ? DmarcRecord::parse($raw) : null;
    }

    public static function label(string $policy, int $pct): string
    {
        return 'p='.$policy.($policy !== 'none' && $pct < 100 ? " ({$pct}%)" : '');
    }
}
