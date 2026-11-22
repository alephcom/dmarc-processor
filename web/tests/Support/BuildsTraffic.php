<?php

namespace Tests\Support;

use App\Intel\SourceRegistry;
use App\Models\Domain;
use App\Models\IpIntel;
use App\Models\Report;
use App\Services\StatsAggregator;
use Illuminate\Support\Facades\DB;

trait BuildsTraffic
{
    /** Add one day of mail from $ip for $domain: $pass messages passing, $fail failing. */
    protected function traffic(Domain $domain, string $ip, string $day, int $pass, int $fail = 0, ?IpIntel $intel = null): void
    {
        $report = Report::create([
            'domain_id' => $domain->id, 'reporter_org' => 'google.com', 'report_id' => uniqid('', true), 'policy_domain' => $domain->name,
            'date_begin' => $day, 'date_end' => $day, 'policy_published' => [], 'message_count' => $pass + $fail,
        ]);
        $rows = [];
        foreach ([[$pass, true], [$fail, false]] as [$count, $ok]) {
            if ($count > 0) {
                $rows[] = [
                    'report_id' => $report->id, 'domain_id' => $domain->id, 'day' => $day, 'source_ip' => $ip, 'count' => $count,
                    'disposition' => 'none', 'dkim_aligned' => $ok, 'spf_aligned' => $ok, 'header_from' => $domain->name,
                    'dkim_results' => json_encode([]), 'spf_results' => json_encode([]), 'reasons' => null,
                ];
            }
        }
        DB::table('report_records')->insert($rows);
        app(StatsAggregator::class)->refreshDay($domain->id, $day);
        app(SourceRegistry::class)->sync($domain->id);
    }

    protected function daysAgo(int $n): string
    {
        return now()->subDays($n)->toDateString();
    }
}
