<?php

namespace App\Intel;

use App\Models\KnownSender;
use Illuminate\Support\Collection;

/**
 * Decides which known sender (if any) an IP belongs to.
 *
 * Evidence (both verified by us in live DNS / the IP database, never taken from report contents):
 *  1. rdns  - forward-confirmed reverse DNS ends with a sender's suffix
 *  2. asn   - the IP is in a network dedicated to the sender
 * Unconfirmed rDNS is never used: anyone can set the PTR of their own IP to "mail.google.com".
 * Report auth_results (SPF/DKIM domains) are never used: reports are unauthenticated input.
 */
class SenderIdentifier
{
    /** @var Collection<int, KnownSender>|null */
    private ?Collection $senders = null;

    /** @return array{0: KnownSender, 1: string}|null [sender, reason] */
    public function identify(?string $rdns, bool $rdnsVerified, ?int $asn): ?array
    {
        $senders = $this->senders();

        if ($rdns !== null && $rdnsVerified) {
            foreach ($senders as $s) {
                if (self::matchesAny($rdns, $s->rdns_suffixes ?? [])) {
                    return [$s, 'rdns'];
                }
            }
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
