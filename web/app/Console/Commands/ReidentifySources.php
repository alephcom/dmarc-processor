<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSourceIps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReidentifySources extends Command
{
    protected $signature = 'dmarc:reidentify-sources';

    protected $description = 'Re-run sender identification for every source IP (after editing known senders or updating the IP database)';

    public function handle(): int
    {
        $count = 0;
        DB::table('daily_source_stats')->select('source_ip')->distinct()->orderBy('source_ip')
            ->chunk(500, function ($rows) use (&$count) {
                foreach ($rows->pluck('source_ip')->chunk(100) as $ips) {
                    EnrichSourceIps::dispatch($ips->values()->all(), force: true);
                }
                $count += $rows->count();
            });
        $this->info("Queued re-identification of {$count} IPs.");

        return self::SUCCESS;
    }
}
