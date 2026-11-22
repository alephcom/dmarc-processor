<?php

namespace App\Intel;

use App\Models\KnownSender;
use Illuminate\Support\Collection;

/**
 * Decides which known sender (if any) an IP belongs to.
 *
 * Evidence, strongest first, all verified by us (live DNS / the IP database), never taken from reports:
 *  1. rdns  - forward-confirmed reverse DNS ends with a sender's suffix
 *  2. spf   - the IP is inside the ranges the sender's own SPF record authorises
 *             (see SpfRangeIndex; most specific, then most direct, wins; ties mean no match)
 *  3. asn   - the IP is in a network dedicated to the sender
 * Unconfirmed rDNS is never used: anyone can set the PTR of their own IP to "mail.google.com".
 * Report auth_results (SPF/DKIM results) are never used: reports are unauthenticated input.
 */
class SenderIdentifier
{
    /** @var Collection<int, KnownSender>|null */
    private ?Collection $senders = null;

    public function __construct(private readonly SpfRangeIndex $spf) {}

    /** @return array{0: KnownSender, 1: string}|null [sender, reason] */
    public function identify(?string $rdns, bool $rdnsVerified, ?int $asn, ?string $ip = null): ?array
    {
        $senders = $this->senders();

        if ($rdns !== null && $rdnsVerified) {
            foreach ($senders as $s) {
                if (self::matchesAny($rdns, $s->rdns_suffixes ?? [])) {
                    return [$s, 'rdns'];
                }
            }
        }

        if ($ip !== null && ($match = $this->spfMatch($ip, $senders)) !== null) {
            return [$match, 'spf'];
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

    /** @param Collection<int, KnownSender> $senders */
    private function spfMatch(string $ip, Collection $senders): ?KnownSender
    {
        $best = null;
        $bestSenders = [];
        foreach ($senders as $s) {
            $m = $this->spf->bestMatch($ip, $s->spf_includes ?? []);
            if ($m === null) {
                continue;
            }
            if ($best === null || SpfRangeIndex::better($m, $best)) {
                $best = $m;
                $bestSenders = [$s];
            } elseif ($m === $best) {
                $bestSenders[] = $s;
            }
        }

        // Two senders claiming the IP equally (same prefix, same depth): don't guess.
        return count($bestSenders) === 1 ? $bestSenders[0] : null;
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
