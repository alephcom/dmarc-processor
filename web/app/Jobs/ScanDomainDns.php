<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\DomainScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanDomainDns implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $domainId, public readonly bool $full = true) {}

    public function uniqueId(): string
    {
        return $this->domainId.':'.($this->full ? 'full' : 'verify');
    }

    public function handle(DomainScanner $scanner): void
    {
        $domain = Domain::find($this->domainId);
        if (! $domain || $domain->status === Domain::STATUS_PAUSED) {
            return;
        }
        $this->full ? $scanner->scan($domain) : $scanner->verify($domain);
    }
}
