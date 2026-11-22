<?php

namespace App\Intel;

use App\Models\KnownSender;
use Illuminate\Support\Collection;

/**
 * Decides which known sender (if any) an IP belongs to.
 *
 * Evidence, strongest first:
 *  1. rdns  - forward-confirmed reverse DNS ends with a sender's suffix
 *  2. spf   - SPF *passed* for an envelope domain belonging to the sender. SPF is bound to the
 *             sending IP; DKIM is deliberately not used because signatures survive forwarding.
 *  3. asn   - the IP is in a network dedicated to the sender
 * Unconfirmed rDNS is never used: anyone can set the PTR of their own IP to "mail.google.com".
 */
class SenderIdentifier
{
    /** @var Collection<int, KnownSender>|null */
    private ?Collection $senders = null;

    /**
     * @param  list<string>  $passingSpfDomains  envelope domains with a passing SPF result on mail from this IP
     * @return array{0: KnownSender, 1: string}|null [sender, reason]
     */
    public function identify(?string $rdns, bool $rdnsVerified, ?int $asn, array $passingSpfDomains): ?array
    {
        $senders = $this->senders();

        if ($rdns !== null && $rdnsVerified) {
            foreach ($senders as $s) {
                if (self::matchesAny($rdns, $s->rdns_suffixes ?? [])) {
                    return [$s, 'rdns'];
                }
            }
        }

        // Count which sender the passing SPF domains point at; most evidence wins.
        $votes = [];
        foreach ($passingSpfDomains as $d) {
            foreach ($senders as $s) {
                if (self::matchesAny($d, [...($s->dkim_domains ?? []), ...($s->spf_includes ?? [])])) {
                    $votes[$s->id] = ($votes[$s->id] ?? 0) + 1;
                }
            }
        }
        if ($votes !== []) {
            arsort($votes);

            return [$senders->firstWhere('id', array_key_first($votes)), 'spf'];
        }

        if ($asn !== null) {
            foreach ($senders as $s) {
                if (in_array($asn, array_map('intval', $s->asns ?? []), true)) {
                    return [$s, 'asn'];
                }
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->senders = null;
    }

    /** @param list<string> $suffixes */
    public static function matchesAny(string $host, array $suffixes): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($suffixes as $suffix) {
            $suffix = strtolower(trim((string) $suffix, '. '));
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, KnownSender> */
    private function senders(): Collection
    {
        return $this->senders ??= KnownSender::orderBy('id')->get();
    }
}
