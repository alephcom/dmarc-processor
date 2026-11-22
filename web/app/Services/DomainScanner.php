<?php

namespace App\Services;

use App\Dns\Checks\CheckResult;
use App\Dns\Checks\DkimChecker;
use App\Dns\Checks\DmarcAnalyzer;
use App\Dns\Checks\DmarcRecord;
use App\Dns\Checks\DmarcRecordBuilder;
use App\Dns\Checks\Issue;
use App\Dns\Checks\ReportAuthorisationCheck;
use App\Dns\Checks\SpfAnalyzer;
use App\Dns\DnsProviders;
use App\Models\DnsCheck;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Runs every DNS check for a domain, stores the results and updates the domain's DNS status.
 */
class DomainScanner
{
    public function __construct(
        private readonly DmarcAnalyzer $dmarc,
        private readonly SpfAnalyzer $spf,
        private readonly DkimChecker $dkim,
        private readonly ReportAuthorisationCheck $reportAuth,
        private readonly DnsProviders $providers,
        private readonly DmarcRecordBuilder $builder,
    ) {}

    public function scan(Domain $domain): ScanResult
    {
        $rua = $domain->ruaAddress();
        $dmarc = $this->dmarc->analyze($domain->name, $rua);
        // Merge into the existing record, unless there are several (then start from a clean one).
        $existing = $dmarc->raw !== null && ! $dmarc->hasIssue('multiple_records') ? DmarcRecord::parse($dmarc->raw) : null;

        $result = new ScanResult(
            dmarc: $dmarc,
            spf: $this->spf->analyze($domain->name),
            dkim: $this->dkim->check($domain->name, [...($domain->dkim_selectors ?? []), ...$this->observedSelectors($domain)]),
            reportAuth: $this->reportAuth->check($domain->name, config('dmarc.reports_domain')),
            provider: $this->providers->detect($domain->name) ?? $domain->dns_provider,
            suggestedDmarc: $this->builder->build($existing, $rua),
        );

        $this->persist($domain, $result->all(), $result->provider, $result->verified());

        return $result;
    }

    /**
     * Light re-check used while waiting for the customer to publish their record:
     * only the DMARC record and the report authorisation, no DKIM/SPF probing.
     */
    public function verify(Domain $domain): ScanResult
    {
        $rua = $domain->ruaAddress();
        $dmarc = $this->dmarc->analyze($domain->name, $rua);
        $auth = $this->reportAuth->check($domain->name, config('dmarc.reports_domain'));
        $existing = $dmarc->raw !== null && ! $dmarc->hasIssue('multiple_records') ? DmarcRecord::parse($dmarc->raw) : null;
        $latest = $domain->latestChecks();

        $result = new ScanResult(
            dmarc: $dmarc,
            spf: $latest['spf'] ?? new CheckResult('spf', CheckResult::UNKNOWN),
            dkim: $latest['dkim'] ?? new CheckResult('dkim', CheckResult::UNKNOWN),
            reportAuth: $auth,
            provider: $domain->dns_provider,
            suggestedDmarc: $this->builder->build($existing, $rua),
        );
        $this->persist($domain, ['dmarc' => $dmarc, 'rua_auth' => $auth], $domain->dns_provider, $result->verified());

        return $result;
    }

    /** @param array<string, CheckResult> $checks */
    private function persist(Domain $domain, array $checks, ?string $provider, bool $verified): void
    {
        $now = now();
        DB::transaction(function () use ($domain, $checks, $provider, $verified, $now) {
            foreach ($checks as $type => $check) {
                DnsCheck::create([
                    'domain_id' => $domain->id,
                    'type' => $type,
                    'raw' => $check->raw,
                    'parsed' => $check->parsed,
                    'issues' => array_map(fn (Issue $i) => $i->toArray(), $check->issues),
                    'status' => $check->status,
                    'checked_at' => $now,
                ]);
            }

            $updates = ['dns_checked_at' => $now, 'dns_provider' => $provider];
            $dmarc = $checks['dmarc'] ?? null;
            if ($dmarc && $dmarc->status !== CheckResult::UNKNOWN) {
                $updates['current_policy'] = $dmarc->parsed['policy'] ?? null;
                $updates['current_pct'] = isset($dmarc->parsed['policy']) ? ($dmarc->parsed['pct'] ?? 100) : null;
                $updates['dns_verified_at'] = $verified ? ($domain->dns_verified_at ?? $now) : null;
            }
            $domain->forceFill($updates)->save();
        });
    }

    /**
     * DKIM selectors that signed this domain's mail in recent reports.
     *
     * @return list<string>
     */
    public function observedSelectors(Domain $domain): array
    {
        $selectors = [];
        DB::table('report_records')
            ->where('domain_id', $domain->id)
            ->where('day', '>=', now()->subDays(30)->toDateString())
            ->whereNotNull('dkim_results')
            ->orderByDesc('id')
            ->limit(5000)
            ->pluck('dkim_results')
            ->each(function ($json) use (&$selectors, $domain) {
                foreach (json_decode((string) $json, true) ?: [] as $r) {
                    $d = strtolower($r['domain'] ?? '');
                    if (! empty($r['selector']) && ($d === $domain->name || str_ends_with($d, '.'.$domain->name))) {
                        $selectors[strtolower($r['selector'])] = true;
                    }
                }
            });

        return array_keys($selectors);
    }
}
