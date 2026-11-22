<?php

namespace App\Console\Commands;

use App\Jobs\ScanDomainDns;
use App\Models\Domain;
use Illuminate\Console\Command;

class CheckDns extends Command
{
    protected $signature = 'dmarc:check-dns
        {--pending : Only re-check DMARC for recently added domains that are not verified yet}
        {--domain= : Check a single domain by name, synchronously}';

    protected $description = 'Queue DNS checks for monitored domains';

    public function handle(): int
    {
        if ($name = $this->option('domain')) {
            $domain = Domain::where('name', strtolower($name))->first();
            if (! $domain) {
                $this->error("Unknown domain {$name}");

                return self::FAILURE;
            }
            ScanDomainDns::dispatchSync($domain->id, true);
            $domain->refresh();
            $this->info("{$domain->name}: ".($domain->dns_verified_at ? 'DMARC record verified' : 'DMARC record not verified'));

            return self::SUCCESS;
        }

        $query = Domain::query()->where('status', '!=', Domain::STATUS_PAUSED);
        if ($this->option('pending')) {
            // Spec: re-check every 15 minutes for 48 hours after a domain is added.
            $query->whereNull('dns_verified_at')->where('created_at', '>=', now()->subHours(48));
        }

        $count = 0;
        $query->select('id')->chunkById(200, function ($domains) use (&$count) {
            foreach ($domains as $d) {
                ScanDomainDns::dispatch($d->id, ! $this->option('pending'));
                $count++;
            }
        });
        $this->info("Queued {$count} DNS checks.");

        return self::SUCCESS;
    }
}
