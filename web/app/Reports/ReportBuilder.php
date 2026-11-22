<?php

namespace App\Reports;

use App\Models\Alert;
use App\Models\Domain;
use App\Models\Report;
use App\Models\Tenant;
use App\Services\DomainStats;
use App\Services\PolicyAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportBuilder
{
    public function __construct(private readonly DomainStats $stats, private readonly PolicyAdvisor $advisor) {}

    /** @return array<string, mixed> */
    public function domain(Domain $domain, int $days): array
    {
        $t = $this->stats->totals($domain->id, $days);
        $from = DomainStats::from($days);

        $sources = DB::table('daily_source_stats AS s')
            ->leftJoin('domain_sources AS d', fn ($j) => $j->on('d.domain_id', '=', 's.domain_id')->on('d.source_key', '=', 's.source_key'))
            ->where('s.domain_id', $domain->id)->where('s.day', '>=', $from)
            ->groupBy('s.source_key', 'd.label', 'd.classification')
            ->selectRaw("s.source_key, COALESCE(d.label, s.source_key) AS label, COALESCE(d.classification, 'unknown') AS classification, "
                .'SUM(s.total) AS total, SUM(s.dmarc_pass) AS pass, SUM(s.dkim_aligned) AS dkim, SUM(s.spf_aligned) AS spf')
            ->orderByDesc('total')->limit(15)->get()
            ->map(fn ($r) => [
                'label' => $r->label,
                'classification' => $r->classification,
                'total' => (int) $r->total,
                'pass_rate' => DomainStats::rate((int) $r->pass, (int) $r->total),
                'dkim_rate' => DomainStats::rate((int) $r->dkim, (int) $r->total),
                'spf_rate' => DomainStats::rate((int) $r->spf, (int) $r->total),
            ])->all();

        return [
            'domain' => $domain,
            'tenant' => $domain->tenant,
            'days' => $days,
            'period' => $this->period($days),
            'totals' => $t,
            'pass_rate' => DomainStats::rate($t['dmarc_pass'], $t['total']),
            'dkim_rate' => DomainStats::rate($t['dkim_aligned'], $t['total']),
            'spf_rate' => DomainStats::rate($t['spf_aligned'], $t['total']),
            'chart' => SvgPassFailChart::render($this->stats->daily($domain->id, $days)),
            'sources' => $sources,
            'reporters' => Report::where('domain_id', $domain->id)->where('date_begin', '>=', $from)
                ->groupBy('reporter_org')->selectRaw('reporter_org, COUNT(*) AS reports, SUM(message_count) AS messages')
                ->orderByDesc('messages')->limit(10)->get(),
            'advice' => $this->advisor->advise($domain),
            'alerts' => Alert::open()->where('domain_id', $domain->id)->latest('opened_at')->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function tenant(Tenant $tenant, int $days): array
    {
        $domains = Domain::where('tenant_id', $tenant->id)->orderBy('name')->get();
        $ids = $domains->pluck('id')->all();
        $t = $this->stats->totals($ids, $days);

        return [
            'tenant' => $tenant,
            'days' => $days,
            'period' => $this->period($days),
            'totals' => $t,
            'pass_rate' => DomainStats::rate($t['dmarc_pass'], $t['total']),
            'chart' => SvgPassFailChart::render($this->stats->daily($ids, $days)),
            'domains' => $domains->map(function (Domain $d) use ($days) {
                $dt = $this->stats->totals($d->id, $days);
                $advice = $this->advisor->advise($d);

                return [
                    'name' => $d->name,
                    'live' => $d->dns_verified_at !== null,
                    'policy' => $advice->current,
                    'messages' => $dt['total'],
                    'pass_rate' => DomainStats::rate($dt['dmarc_pass'], $dt['total']),
                    'next' => $advice->headline,
                    'review' => $this->stats->unknownFailingSources($d->id, $days),
                ];
            })->all(),
            'alerts' => Alert::open()->where('tenant_id', $tenant->id)->with('domain')->latest('opened_at')->get(),
        ];
    }

    private function period(int $days): string
    {
        $end = CarbonImmutable::now('UTC');

        return $end->subDays($days - 1)->format('j M Y').' – '.$end->format('j M Y');
    }
}
