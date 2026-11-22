<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes raw reports, records and stored emails past each tenant's retention period.
 * Daily rollups are kept indefinitely.
 */
class PruneReports extends Command
{
    protected $signature = 'dmarc:prune {--dry-run : Report what would be deleted without deleting}';

    protected $description = "Delete raw DMARC data older than each tenant's retention period";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk(config('dmarc.raw_disk'));

        Tenant::query()->each(function (Tenant $tenant) use ($dry, $disk) {
            $days = max(30, (int) ($tenant->retention_days_raw ?: config('dmarc.default_retention_days')));
            $cutoff = now()->subDays($days);
            $domainIds = Domain::where('tenant_id', $tenant->id)->pluck('id');
            if ($domainIds->isEmpty()) {
                return;
            }

            $reports = Report::whereIn('domain_id', $domainIds)->where('date_end', '<', $cutoff);
            $messages = InboundMessage::whereIn('domain_id', $domainIds)->where('received_at', '<', $cutoff);
            $this->line(sprintf('%s: %d reports, %d messages older than %s', $tenant->name, (clone $reports)->count(), (clone $messages)->count(), $cutoff->toDateString()));
            if ($dry) {
                return;
            }

            // report_records cascade on report delete.
            (clone $reports)->select('id')->chunkById(500, fn ($chunk) => Report::whereIn('id', $chunk->pluck('id'))->delete());

            (clone $messages)->chunkById(500, function ($chunk) use ($disk) {
                foreach ($chunk as $m) {
                    // Raw files can be shared by several tokens on the same spool id; delete when last reference goes.
                    if (InboundMessage::where('raw_path', $m->raw_path)->whereKeyNot($m->id)->doesntExist()) {
                        $disk->delete($m->raw_path);
                    }
                    $m->delete();
                }
            });
        });

        // Unlinked messages (unknown token / deleted domain) use the default retention.
        $cutoff = now()->subDays((int) config('dmarc.default_retention_days'));
        if (! $dry) {
            InboundMessage::whereNull('domain_id')->where('received_at', '<', $cutoff)->chunkById(500, function ($chunk) use ($disk) {
                foreach ($chunk as $m) {
                    if (InboundMessage::where('raw_path', $m->raw_path)->whereKeyNot($m->id)->doesntExist()) {
                        $disk->delete($m->raw_path);
                    }
                    $m->delete();
                }
            });
        }

        return self::SUCCESS;
    }
}
