<?php

namespace App\Intel;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;
use App\Models\IpIntel;

/**
 * Looks up reverse DNS, ASN and known-sender identity for source IPs and caches it in ip_intel.
 *
 * ip_intel is shared by every tenant, so only signals we verify ourselves are used. Nothing in a
 * DMARC report counts: reports are unauthenticated, and anyone who knows a published rua address
 * could otherwise relabel an IP for all tenants (e.g. claim an SPF pass for sendgrid.net).
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
        $match = $this->identifier->identify($rdns, $verified, $asn?->asn, $ip);

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
}
