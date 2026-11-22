<?php

namespace App\Dns;

interface Resolver
{
    /**
     * TXT records at $name, each with its character-strings concatenated.
     * Returns [] when the name has no TXT records or does not exist.
     *
     * @return list<string>
     *
     * @throws DnsLookupException on resolver failure (timeout, SERVFAIL)
     */
    public function txt(string $name): array;

    /** @return list<string> lower-case nameserver hostnames */
    public function ns(string $name): array;

    /** @return list<string> lower-case mail exchanger hostnames */
    public function mx(string $name): array;

    /** Whether $name has any A or AAAA record. */
    public function hasAddress(string $name): bool;
}
