<?php

namespace App\Intel;

use MaxMind\Db\Reader;
use Throwable;

/**
 * Reads an offline IP-to-ASN database in MaxMind DB format. Supports:
 *  - IPinfo Lite (asn: "AS15169", as_name, country_code)
 *  - DB-IP ASN Lite / GeoLite2-ASN (autonomous_system_number, autonomous_system_organization)
 */
class MmdbAsnDatabase implements AsnDatabase
{
    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly string $path) {}

    public function available(): bool
    {
        return $this->reader() !== null;
    }

    public function lookup(string $ip): ?AsnInfo
    {
        $reader = $this->reader();
        if ($reader === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        try {
            $r = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($r)) {
            return null;
        }

        if (isset($r['autonomous_system_number'])) {
            return new AsnInfo((int) $r['autonomous_system_number'], $r['autonomous_system_organization'] ?? null, $r['country_code'] ?? null);
        }
        if (isset($r['asn']) && preg_match('/^AS(\d+)$/i', (string) $r['asn'], $m)) {
            return new AsnInfo((int) $m[1], $r['as_name'] ?? null, $r['country_code'] ?? null);
        }

        return null;
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            if (is_readable($this->path)) {
                try {
                    $this->reader = new Reader($this->path);
                } catch (Throwable) {
                    $this->reader = null;
                }
            }
        }

        return $this->reader;
    }
}
