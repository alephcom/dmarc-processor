<?php

namespace App\Dns;

/**
 * Uses the system resolver via dns_get_record(). In Docker that is Docker's embedded DNS,
 * which forwards to the host's upstream resolvers.
 */
class SystemResolver implements Resolver
{
    public function txt(string $name): array
    {
        $out = [];
        foreach ($this->query($name, DNS_TXT) as $r) {
            if (($r['type'] ?? '') !== 'TXT') {
                continue;
            }
            // 'entries' holds the individual character-strings; RFC 7208/7489 say to join them with no separator.
            $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
        }

        return $out;
    }

    public function ns(string $name): array
    {
        return array_values(array_map(fn ($r) => strtolower(rtrim($r['target'], '.')),
            array_filter($this->query($name, DNS_NS), fn ($r) => isset($r['target']))));
    }

    public function mx(string $name): array
    {
        $records = array_filter($this->query($name, DNS_MX), fn ($r) => isset($r['target']));
        usort($records, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_map(fn ($r) => strtolower(rtrim($r['target'], '.')), $records));
    }

    public function hasAddress(string $name): bool
    {
        return $this->query($name, DNS_A) !== [] || $this->query($name, DNS_AAAA) !== [];
    }

    public function addresses(string $name): array
    {
        $out = [];
        foreach ($this->query($name, DNS_A) as $r) {
            if (isset($r['ip'])) {
                $out[] = $r['ip'];
            }
        }
        foreach ($this->query($name, DNS_AAAA) as $r) {
            if (isset($r['ipv6'])) {
                $out[] = inet_ntop(inet_pton($r['ipv6']));
            }
        }

        return $out;
    }

    public function ptr(string $ip): ?string
    {
        $name = ReverseName::for($ip);
        if ($name === null) {
            return null;
        }
        foreach ($this->query($name, DNS_PTR) as $r) {
            if (! empty($r['target'])) {
                return strtolower(rtrim($r['target'], '.'));
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function query(string $name, int $type): array
    {
        $name = rtrim($name, '.');
        if ($name === '' || strlen($name) > 253) {
            return [];
        }
        $result = @dns_get_record($name, $type);
        if ($result === false) {
            // dns_get_record() returns false both for NXDOMAIN on some platforms and for real failures.
            // Treat "no such name" as empty and everything else as an error the caller can show.
            $err = error_get_last()['message'] ?? '';
            if (stripos($err, 'temporary') !== false || stripos($err, 'timed out') !== false || stripos($err, 'SERVFAIL') !== false) {
                throw new DnsLookupException("DNS lookup for {$name} failed: {$err}");
            }

            return [];
        }

        return $result;
    }
}
