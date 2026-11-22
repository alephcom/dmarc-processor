<?php

namespace App\Dns;

/**
 * In-memory resolver for tests and local development.
 *
 * Keys are lower-case names; values are ['TXT' => [...], 'NS' => [...], 'MX' => [...], 'A' => true].
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
