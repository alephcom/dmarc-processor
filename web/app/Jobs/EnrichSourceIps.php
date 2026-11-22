<?php

namespace App\Jobs;

use App\Intel\IpEnricher;
use App\Intel\SourceRegistry;
use App\Models\IpIntel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichSourceIps implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /** @param list<string> $ips */
    public function __construct(public readonly array $ips, public readonly bool $force = false) {}

    public function handle(IpEnricher $enricher, SourceRegistry $registry): void
    {
        foreach (array_unique($this->ips) as $ip) {
            if ($this->force || $enricher->isStale(IpIntel::firstWhere('ip', $ip))) {
                $enricher->enrich($ip);
            }
            $registry->rekeyIp($ip);
        }
    }
}
