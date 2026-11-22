<?php

namespace App\Digests;

use App\Models\Alert;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Models\Tenant;
use App\Services\DomainStats;
use App\Services\PolicyAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DigestBuilder
{
    public function __construct(private readonly DomainStats $stats, private readonly PolicyAdvisor $advisor) {}

    /** @return array<string, mixed> */
    public function forTenant(Tenant $tenant, int $days): array
    {
        $domains = Domain::where('tenant_id', $tenant->id)->orderBy('name')->get();
        $rows = $domains->map(fn (Domain $d) => $this->domainSummary($d, $days))->all();

        return [
            'tenant' => $tenant,
            'days' => $days,
            'period' => $this->periodLabel($days),
            'domains' => $rows,
            'open_alerts' => Alert::open()->where('tenant_id', $tenant->id)->orderByDesc('severity')->latest('opened_at')->limit(10)->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function forMsp(int $days): array
    {
        $tenants = Tenant::with('domains')->orderBy('name')->get()->map(function (Tenant $t) use ($days) {
            $ids = $t->domains->pluck('id')->all();
            $tot = $this->stats->totals($ids, $days);

            return [
                'name' => $t->name,
                'domains' => count($ids),
                'live' => $t->domains->whereNotNull('dns_verified_at')->count(),
                'messages' => $tot['total'],
                'pass_rate' => DomainStats::rate($tot['dmarc_pass'], $tot['total']),
                'open_alerts' => Alert::open()->where('tenant_id', $t->id)->count(),
            ];
        })->all();

        return [
            'days' => $days,
            'period' => $this->periodLabel($days),
            'tenants' => $tenants,
            'silent' => Domain::with('tenant')->where('status', Domain::STATUS_ACTIVE)->where('last_report_at', '<', now()->subHours(72))->get(),
            'msp_alerts' => Alert::open()->whereNull('tenant_id')->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function domainSummary(Domain $d, int $days): array
    {
        $now = $this->stats->totals($d->id, $days);
        $from = DomainStats::from($days);
        $prevFrom = CarbonImmutable::parse($from)->subDays($days)->toDateString();
        $prev = DB::table('daily_domain_stats')->where('domain_id', $d->id)->where('day', '>=', $prevFrom)->where('day', '<', $from)
            ->selectRaw('COALESCE(SUM(total),0) AS total, COALESCE(SUM(dmarc_pass),0) AS pass')->first();
        $rate = DomainStats::rate($now['dmarc_pass'], $now['total']);
        $prevRate = DomainStats::rate((int) $prev->pass, (int) $prev->total);

        $failing = DB::table('daily_source_stats AS s')
            ->join('domain_sources AS ds', fn ($j) => $j->on('ds.domain_id', '=', 's.domain_id')->on('ds.source_key', '=', 's.source_key'))
            ->where('s.domain_id', $d->id)->where('s.day', '>=', $from)
            ->groupBy('ds.label', 'ds.classification')
            ->havingRaw('SUM(s.total) > SUM(s.dmarc_pass)')
            ->selectRaw('ds.label, ds.classification, SUM(s.total) - SUM(s.dmarc_pass) AS failing')
            ->orderByDesc('failing')->get();
        $list = fn ($rows) => $rows->take(3)->map(fn ($r) => ['label' => $r->label, 'failing' => (int) $r->failing])->values()->all();

        return [
            'name' => $d->name,
            'messages' => $now['total'],
            'pass_rate' => $rate,
            'change' => $rate !== null && $prevRate !== null ? round($rate - $prevRate, 1) : null,
            'new_senders' => DomainSource::where('domain_id', $d->id)->where('first_seen_at', '>=', $from)->pluck('label')->all(),
            // Your own services failing: fixable, and they would be blocked under quarantine/reject.
            'legit_failing' => $list($failing->where('classification', 'legit')),
            // Everyone else failing: unrecognised senders or spoofing.
            'other_failing' => $list($failing->where('classification', '!=', 'legit')),
            'advice' => $this->advisor->advise($d),
        ];
    }

    private function periodLabel(int $days): string
    {
        $end = CarbonImmutable::now('UTC');

        return $end->subDays($days - 1)->format('j M').' – '.$end->format('j M Y');
    }
}
