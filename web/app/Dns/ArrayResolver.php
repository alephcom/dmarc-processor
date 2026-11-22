<?php

namespace App\Dns;

/**
 * In-memory resolver for tests and local development.
 *
 * Keys are lower-case names; values are ['TXT' => [...], 'NS' => [...], 'MX' => [...], 'A' => true|[ips], 'PTR' => 'host'].
 * PTR entries live under the reverse name (e.g. 1.2.0.192.in-addr.arpa); see ReverseName.
 * Set a name to 'SERVFAIL' to simulate a resolver failure.
 */
class ArrayResolver implements Resolver
{
    /** @var array<string, int> */
    public array $queries = [];

    /** @param array<string, array<string, mixed>|string> $zone */
    public function __construct(public array $zone = []) {}

    public function set(string $name, string $type, mixed $value): static
    {
        $this->zone[strtolower($name)][$type] = $value;

        return $this;
    }

    public function txt(string $name): array
    {
        return array_values($this->get($name, 'TXT') ?? []);
    }

    public function ns(string $name): array
    {
        return array_values($this->get($name, 'NS') ?? []);
    }

    public function mx(string $name): array
    {
        return array_values($this->get($name, 'MX') ?? []);
    }

    public function hasAddress(string $name): bool
    {
        return (bool) ($this->get($name, 'A') ?? false);
    }

    public function addresses(string $name): array
    {
        $v = $this->get($name, 'A');

        return is_array($v) ? array_values($v) : [];
    }

    public function ptr(string $ip): ?string
    {
        $name = ReverseName::for($ip);
        $v = $name === null ? null : $this->get($name, 'PTR');

        return is_string($v) ? strtolower($v) : null;
    }

    private function get(string $name, string $type): mixed
    {
        $name = strtolower(rtrim($name, '.'));
        $this->queries[$name] = ($this->queries[$name] ?? 0) + 1;
        $entry = $this->zone[$name] ?? null;
        if ($entry === 'SERVFAIL') {
            throw new DnsLookupException("DNS lookup for {$name} failed: SERVFAIL");
        }

        return is_array($entry) ? ($entry[$type] ?? null) : null;
    }
}
