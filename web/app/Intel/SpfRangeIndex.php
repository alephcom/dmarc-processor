<?php

namespace App\Intel;

use App\Dns\DnsLookupException;
use App\Dns\Resolver;
use App\Support\Cidr;
use Illuminate\Support\Facades\Cache;

/**
 * IP ranges a sender authorises in the SPF record published on its *own* domain
 * (e.g. include:sendgrid.net → SendGrid's sending ranges), fetched from live DNS.
 *
 * This is evidence we verify ourselves: the ranges come from the provider's DNS, which a
 * report sender can't influence, unlike SPF results written inside a report.
 *
 * Each range carries its depth (1 = listed directly in the include's own record, 2 = one
 * include further down...). When several senders cover an IP, the most specific range wins,
 * then the shallowest. A company domain that includes another provider's record (say a
 * CRM whose SPF includes Google's) therefore loses to the provider itself.
 */
class SpfRangeIndex
{
    public const CACHE_SECONDS = 86400;

    public const FAILURE_CACHE_SECONDS = 300;

    private const MAX_LOOKUPS = 40;

    private const MAX_DEPTH = 8;

    private int $lookups = 0;

    /** @var array<string, true> */
    private array $visiting = [];

    public function __construct(private readonly Resolver $dns) {}

    /**
     * @return list<array{0: string, 1: int}> [cidr, depth]
     */
    public function rangesFor(string $domain): array
    {
        $domain = strtolower(trim($domain, '. '));
        if ($domain === '' || ! preg_match('/^[a-z0-9._-]+\.[a-z]{2,}$/', $domain)) {
            return [];
        }

        $key = 'spf-ranges:'.$domain;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $this->lookups = 0;
        $this->visiting = [];
        try {
            $ranges = $this->collect($domain, 1);
            Cache::put($key, $ranges, self::CACHE_SECONDS);
        } catch (DnsLookupException) {
            // Don't hold on to a failure; never treat it as a match.
            $ranges = [];
            Cache::put($key, $ranges, self::FAILURE_CACHE_SECONDS);
        }

        return $ranges;
    }

    /**
     * Best (most specific, then shallowest) range covering $ip among a sender's SPF domains.
     *
     * @param  list<string>  $domains
     * @return array{prefix: int, depth: int}|null
     */
    public function bestMatch(string $ip, array $domains): ?array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $v6 = str_contains($ip, ':');
        $best = null;
        foreach ($domains as $domain) {
            foreach ($this->rangesFor((string) $domain) as [$cidr, $depth]) {
                if (Cidr::isIpv6($cidr) !== $v6 || ! Cidr::contains($cidr, $ip)) {
                    continue;
                }
                $candidate = ['prefix' => Cidr::prefix($cidr), 'depth' => $depth];
                if ($best === null || self::better($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /** @param array{prefix: int, depth: int} $a @param array{prefix: int, depth: int} $b */
    public static function better(array $a, array $b): bool
    {
        return $a['prefix'] > $b['prefix'] || ($a['prefix'] === $b['prefix'] && $a['depth'] < $b['depth']);
    }

    /** @return list<array{0: string, 1: int}> */
    private function collect(string $domain, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || isset($this->visiting[$domain]) || ++$this->lookups > self::MAX_LOOKUPS) {
            return [];
        }
        $this->visiting[$domain] = true;

        $record = null;
        foreach ($this->dns->txt($domain) as $txt) {
            if (preg_match('/^v=spf1(\s|$)/i', trim($txt))) {
                $record = trim($txt);
                break;
            }
        }
        if ($record === null) {
            return [];
        }

        $ranges = [];
        $redirect = null;
        $hasAll = false;
        foreach (array_slice(preg_split('/\s+/', $record), 1) as $term) {
            if (preg_match('/^redirect=(.+)$/i', $term, $m)) {
                $redirect = strtolower($m[1]);

                continue;
            }
            // Only "pass" terms authorise senders; -ip4: and ~include: don't.
            if (in_array($term[0] ?? '', ['-', '~', '?'], true)) {
                if (preg_match('/^.all$/i', $term)) {
                    $hasAll = true;
                }

                continue;
            }
            $term = ltrim($term, '+');
            if (! preg_match('/^([a-z0-9]+)(?::([^\/]*))?(?:\/(\d+))?(?:\/\/(\d+))?$/i', $term, $m)) {
                continue;
            }
            $mech = strtolower($m[1]);
            $arg = isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : null;
            if ($arg !== null && str_contains($arg, '%{')) {
                continue; // macros can't be expanded without a message
            }
            switch ($mech) {
                case 'ip4':
                case 'ip6':
                    $cidr = $arg === null ? null : $arg.(isset($m[3]) && $m[3] !== '' ? '/'.$m[3] : '');
                    if ($cidr !== null && $this->validCidr($cidr)) {
                        $ranges[] = [$cidr, $depth];
                    }
                    break;
                case 'include':
                    if ($arg !== null) {
                        array_push($ranges, ...$this->collect($arg, $depth + 1));
                    }
                    break;
                case 'a':
                    if (++$this->lookups <= self::MAX_LOOKUPS) {
                        foreach ($this->dns->addresses($arg ?? $domain) as $addr) {
                            $ranges[] = [$this->withPrefix($addr, $m[3] ?? '', $m[4] ?? ''), $depth];
                        }
                    }
                    break;
                case 'mx':
                    if (++$this->lookups <= self::MAX_LOOKUPS) {
                        foreach (array_slice($this->dns->mx($arg ?? $domain), 0, 10) as $host) {
                            foreach ($this->dns->addresses($host) as $addr) {
                                $ranges[] = [$this->withPrefix($addr, $m[3] ?? '', $m[4] ?? ''), $depth];
                            }
                        }
                    }
                    break;
                case 'all':
                    $hasAll = true;
                    break;
                default:
                    // ptr / exists: can't be turned into ranges; ignore.
            }
        }
        if ($redirect !== null && ! $hasAll) {
            array_push($ranges, ...$this->collect($redirect, $depth));
        }

        unset($this->visiting[$domain]);

        return $ranges;
    }

    /** a/24 and mx//64 style suffixes: a v4 and/or v6 prefix applied to each resolved address. */
    private function withPrefix(string $addr, string $v4, string $v6): string
    {
        $bits = str_contains($addr, ':') ? $v6 : $v4;

        return $bits !== '' && $this->validCidr("{$addr}/{$bits}") ? "{$addr}/{$bits}" : $addr;
    }

    private function validCidr(string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (filter_var($net, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = str_contains($net, ':') ? 128 : 32;
        // Refuse absurdly broad ranges (a misconfigured "ip4:0.0.0.0/1" would claim the internet).
        $min = str_contains($net, ':') ? 16 : 8;

        return $bits === null || (ctype_digit($bits) && (int) $bits >= $min && (int) $bits <= $max);
    }
}
