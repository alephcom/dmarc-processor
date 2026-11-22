<?php

namespace App\Intel;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;
use App\Models\IpIntel;
use Illuminate\Support\Facades\DB;

/**
 * Looks up reverse DNS, ASN and known-sender identity for source IPs and caches it in ip_intel.
 */
class IpEnricher
{
    public function __construct(
        private readonly Resolver $dns,
        private readonly AsnDatabase $asnDb,
        private readonly SenderIdentifier $identifier,
    ) {}

    public function isStale(?IpIntel $intel): bool
    {
        return $intel === null || $intel->refreshed_at === null
            || $intel->refreshed_at->lt(now()->subDays((int) config('dmarc.ip_intel_ttl_days')));
    }

    public function enrich(string $ip): IpIntel
    {
        [$rdns, $verified] = $this->reverseDns($ip);
        $asn = $this->asnDb->lookup($ip);
        $match = $this->identifier->identify($rdns, $verified, $asn?->asn, $this->passingSpfDomains($ip));

        return IpIntel::updateOrCreate(['ip' => $ip], [
            'rdns' => $rdns,
            'rdns_verified' => $verified,
            'asn' => $asn?->asn,
            'as_org' => $asn?->org ? mb_substr($asn->org, 0, 255) : null,
            'country' => $asn?->country ? strtoupper(substr($asn->country, 0, 2)) : null,
            'known_sender_id' => $match[0]->id ?? null,
            'match_reason' => $match[1] ?? null,
            'refreshed_at' => now(),
        ]);
    }

    /** @return array{0: ?string, 1: bool} [hostname, forward-confirmed] */
    private function reverseDns(string $ip): array
    {
        try {
            $host = $this->dns->ptr($ip);
            if ($host === null || ! preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
                return [null, false];
            }
            $canonical = inet_ntop(inet_pton($ip));
            $forward = array_map(fn ($a) => inet_ntop(inet_pton($a)), $this->dns->addresses($host));

            return [$host, in_array($canonical, $forward, true)];
        } catch (DnsLookupException) {
            return [null, false];
        }
    }

    /**
     * Envelope domains whose SPF check passed for recent mail from this IP, across all domains.
     *
     * Only SPF is used: it is bound to the sending IP. A DKIM pass is not evidence about the
     * IP, because signatures survive forwarding (a mailing list relaying SendGrid mail would
     * otherwise be labelled SendGrid).
     *
     * @return list<string>
     */
    private function passingSpfDomains(string $ip): array
    {
        $domains = [];
        DB::table('report_records')->where('source_ip', $ip)->orderByDesc('id')->limit(200)
            ->pluck('spf_results')
            ->each(function ($json) use (&$domains) {
                foreach (json_decode((string) $json, true) ?: [] as $r) {
                    if (($r['result'] ?? '') === 'pass' && ! empty($r['domain'])) {
                        $domains[] = strtolower($r['domain']);
                    }
                }
            });

        return $domains;
    }
}
