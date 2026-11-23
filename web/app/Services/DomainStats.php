<?php

namespace App\Services;

use App\Intel\SenderIdentifier;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\IpIntel;
use App\Models\KnownSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-side queries for dashboards. Everything comes from the daily rollups except the
 * per-source drill-down, which reads report_records for the source's IPs.
 */
class DomainStats
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];

    public static function normalisePeriod(mixed $days): int
    {
        $days = (int) $days;

        return array_key_exists($days, self::PERIODS) ? $days : 30;
    }

    /** First day included in a period ending today (UTC). */
    public static function from(int $days): string
    {
        return CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString();
    }

    /**
     * @param  list<int>|int  $domainIds
     * @return array{total: int, dmarc_pass: int, spf_aligned: int, dkim_aligned: int, quarantined: int, rejected: int}
     */
    public function totals(array|int $domainIds, int $days): array
    {
        $row = DB::table('daily_domain_stats')
            ->whereIn('domain_id', (array) $domainIds)
            ->where('day', '>=', self::from($days))
            ->selectRaw('COALESCE(SUM(total),0) AS total, COALESCE(SUM(dmarc_pass),0) AS dmarc_pass, COALESCE(SUM(spf_aligned),0) AS spf_aligned, '
                .'COALESCE(SUM(dkim_aligned),0) AS dkim_aligned, COALESCE(SUM(quarantined),0) AS quarantined, COALESCE(SUM(rejected),0) AS rejected')
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Totals for the period of the same length just before {@see totals()}, for "vs previous period" trends.
     *
     * @param  list<int>|int  $domainIds
     * @return array{total: int, dmarc_pass: int}
     */
    public function previousTotals(array|int $domainIds, int $days): array
    {
        $row = DB::table('daily_domain_stats')
            ->whereIn('domain_id', (array) $domainIds)
            ->where('day', '>=', self::from($days * 2))
            ->where('day', '<', self::from($days))
            ->selectRaw('COALESCE(SUM(total),0) AS total, COALESCE(SUM(dmarc_pass),0) AS dmarc_pass')
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Percentage change from $previous to $current, or null when there is nothing to compare against.
     */
    public static function change(int|float $current, int|float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    public static function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /**
     * One entry per day in the period, zero-filled.
     *
     * @param  list<int>|int  $domainIds
     * @return array<string, array{pass: int, fail: int}>
     */
    public function daily(array|int $domainIds, int $days): array
    {
        $rows = DB::table('daily_domain_stats')
            ->whereIn('domain_id', (array) $domainIds)
            ->where('day', '>=', self::from($days))
            ->groupBy('day')
            ->selectRaw('day, SUM(total) AS total, SUM(dmarc_pass) AS pass')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $out = [];
        $day = CarbonImmutable::parse(self::from($days));
        $end = CarbonImmutable::now('UTC')->startOfDay();
        while ($day <= $end) {
            $r = $rows->get($day->toDateString());
            $pass = (int) ($r->pass ?? 0);
            $out[$day->toDateString()] = ['pass' => $pass, 'fail' => (int) ($r->total ?? 0) - $pass];
            $day = $day->addDay();
        }

        return $out;
    }

    /**
     * Sources sending as the domain in the period that aren't marked legitimate and fail DMARC.
     */
    public function unknownFailingSources(int $domainId, int $days): int
    {
        return DB::table('daily_source_stats AS s')
            ->join('domain_sources AS d', fn ($j) => $j->on('d.domain_id', '=', 's.domain_id')->on('d.source_key', '=', 's.source_key'))
            ->where('s.domain_id', $domainId)
            ->where('s.day', '>=', self::from($days))
            ->where('d.classification', '!=', 'legit')
            ->groupBy('s.source_key')
            ->havingRaw('SUM(s.total) > SUM(s.dmarc_pass)')
            ->select('s.source_key')
            ->get()
            ->count();
    }

    /**
     * Everything needed to explain one source: its IPs, how its mail authenticated, and what to fix.
     *
     * @return array<string, mixed>
     */
    public function sourceDetail(DomainSource $source, int $days): array
    {
        $from = self::from($days);
        $ipRows = DB::table('daily_source_stats')
            ->where('domain_id', $source->domain_id)->where('source_key', $source->source_key)->where('day', '>=', $from)
            ->groupBy('source_ip')
            ->selectRaw('source_ip, SUM(total) AS total, SUM(dmarc_pass) AS pass')
            ->orderByDesc('total')->limit(50)->get();
        $intel = IpIntel::whereIn('ip', $ipRows->pluck('source_ip'))->get()->keyBy('ip');

        $ips = $ipRows->map(fn ($r) => [
            'ip' => $r->source_ip,
            'rdns' => $intel[$r->source_ip]->rdns ?? null,
            'network' => isset($intel[$r->source_ip]) && $intel[$r->source_ip]->asn ? trim('AS'.$intel[$r->source_ip]->asn.' '.$intel[$r->source_ip]->as_org) : null,
            'country' => $intel[$r->source_ip]->country ?? null,
            'total' => (int) $r->total,
            'pass_rate' => self::rate((int) $r->pass, (int) $r->total),
        ])->all();

        // Authentication detail from raw records (bounded so a huge sender can't blow up the page).
        $records = DB::table('report_records')
            ->where('domain_id', $source->domain_id)->where('day', '>=', $from)
            ->whereIn('source_ip', $ipRows->pluck('source_ip'))
            ->orderByDesc('id')->limit(5000)
            ->get(['count', 'dkim_aligned', 'spf_aligned', 'header_from', 'envelope_from', 'dkim_results', 'spf_results', 'reasons']);

        $dkim = $spf = $headerFrom = $reasons = [];
        $total = $dkimAligned = $spfAligned = 0;
        foreach ($records as $r) {
            $n = (int) $r->count;
            $total += $n;
            $dkimAligned += $r->dkim_aligned ? $n : 0;
            $spfAligned += $r->spf_aligned ? $n : 0;
            $headerFrom[$r->header_from] = ($headerFrom[$r->header_from] ?? 0) + $n;
            foreach (json_decode((string) $r->dkim_results, true) ?: [] as $d) {
                $k = $d['domain'].'|'.($d['selector'] ?? '').'|'.$d['result'];
                $dkim[$k] = ($dkim[$k] ?? 0) + $n;
            }
            foreach (json_decode((string) $r->spf_results, true) ?: [] as $s) {
                $k = $s['domain'].'|'.$s['result'];
                $spf[$k] = ($spf[$k] ?? 0) + $n;
            }
            foreach (json_decode((string) $r->reasons, true) ?: [] as $why) {
                $reasons[$why['type']] = ($reasons[$why['type']] ?? 0) + $n;
            }
        }
        arsort($dkim);
        arsort($spf);
        arsort($headerFrom);

        $explode = fn (array $m, array $keys) => array_map(fn ($k, $v) => array_combine($keys, [...explode('|', $k), $v]), array_keys($m), $m);
        $detail = [
            'ips' => $ips,
            'total' => $total,
            'dkim_aligned_rate' => self::rate($dkimAligned, $total),
            'spf_aligned_rate' => self::rate($spfAligned, $total),
            'dkim' => array_slice($explode($dkim, ['domain', 'selector', 'result', 'count']), 0, 15),
            'spf' => array_slice($explode($spf, ['domain', 'result', 'count']), 0, 15),
            'header_from' => array_slice($headerFrom, 0, 10, true),
            'reasons' => $reasons,
        ];
        $detail['advice'] = $this->advice($source, $detail);

        return $detail;
    }

    /**
     * Plain-English next steps for a source, derived from how its mail authenticated.
     *
     * @param  array<string, mixed>  $d
     * @return list<array{level: string, text: string}>
     */
    private function advice(DomainSource $source, array $d): array
    {
        $domain = Domain::find($source->domain_id);
        $sender = str_starts_with($source->source_key, 'sender:') ? KnownSender::find((int) substr($source->source_key, 7)) : null;
        $name = $sender->name ?? 'this sender';
        $dkimOk = ($d['dkim_aligned_rate'] ?? 0) >= 95;
        $spfOk = ($d['spf_aligned_rate'] ?? 0) >= 95;
        $out = [];

        if ($d['total'] === 0) {
            return [];
        }
        if ($dkimOk) {
            $out[] = ['level' => 'success', 'text' => "Mail from {$name} passes DKIM with your domain, so it will keep being delivered when you move to quarantine or reject."];
            if (! $spfOk) {
                $out[] = ['level' => 'info', 'text' => 'SPF doesn\'t align, which is common for email services that use their own bounce address. DKIM alone is enough for DMARC.'];
            }

            return $out;
        }
        if ($spfOk) {
            $out[] = ['level' => 'warning', 'text' => "Mail from {$name} passes only through SPF. That works today but breaks when the message is forwarded. Turn on DKIM signing with your domain in {$name} as well."];

            return $out;
        }

        $foreignDkim = collect($d['dkim'])->first(fn ($x) => $x['result'] === 'pass' && $domain && ! SenderIdentifier::matchesAny($x['domain'], [$domain->name]));
        if ($source->classification === 'legit' || $sender) {
            if ($foreignDkim) {
                $out[] = ['level' => 'danger', 'text' => "{$name} signs your mail with its own domain ({$foreignDkim['domain']}) instead of yours, so DMARC fails. Set up custom DKIM (sometimes called domain authentication) in {$name} for {$domain?->name}."];
            } else {
                $out[] = ['level' => 'danger', 'text' => "Mail from {$name} fails DMARC: it isn't DKIM-signed with your domain and isn't covered by aligned SPF. Set up DKIM for {$domain?->name} in {$name}".($sender?->docs_url ? " (guide: {$sender->docs_url})" : '').'. Until then, moving to quarantine or reject would block this mail.'];
            }
        } else {
            $out[] = ['level' => 'warning', 'text' => 'If you don\'t recognise this sender, mark it as suspicious. Once your policy is quarantine or reject, mail like this is blocked, which is the point of DMARC. If it is one of your services, mark it legitimate and set up DKIM for it.'];
        }
        if (isset($d['reasons']['forwarded']) || isset($d['reasons']['mailing_list'])) {
            $out[] = ['level' => 'info', 'text' => 'Some of this mail was forwarded or went through a mailing list, where failures are expected and usually harmless.'];
        }

        return $out;
    }
}
