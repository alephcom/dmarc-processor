<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds daily rollups for a (domain, day) from report_records.
 * Recomputing (rather than incrementing) keeps rollups correct across retries and deletions.
 */
class StatsAggregator
{
    public function refreshDay(int $domainId, string $day): void
    {
        DB::transaction(function () use ($domainId, $day) {
            $base = DB::table('report_records')->where('domain_id', $domainId)->where('day', $day);

            $totals = (clone $base)->selectRaw($this->sums())->first();
            $reports = DB::table('reports')->where('domain_id', $domainId)
                ->whereDate('date_begin', $day)->count();

            DB::table('daily_domain_stats')->where('domain_id', $domainId)->where('day', $day)->delete();
            if ((int) $totals->total > 0) {
                DB::table('daily_domain_stats')->insert([
                    'domain_id' => $domainId,
                    'day' => $day,
                    'total' => (int) $totals->total,
                    'dmarc_pass' => (int) $totals->dmarc_pass,
                    'dkim_aligned' => (int) $totals->dkim_aligned,
                    'spf_aligned' => (int) $totals->spf_aligned,
                    'quarantined' => (int) $totals->quarantined,
                    'rejected' => (int) $totals->rejected,
                    'reports' => $reports,
                ]);
            }

            DB::table('daily_source_stats')->where('domain_id', $domainId)->where('day', $day)->delete();
            $bySource = (clone $base)->selectRaw('source_ip, '.$this->sums())->groupBy('source_ip')->get();
            $rows = $bySource->map(fn ($r) => [
                'domain_id' => $domainId,
                'day' => $day,
                'source_ip' => $r->source_ip,
                'total' => (int) $r->total,
                'dmarc_pass' => (int) $r->dmarc_pass,
                'dkim_aligned' => (int) $r->dkim_aligned,
                'spf_aligned' => (int) $r->spf_aligned,
                'quarantined' => (int) $r->quarantined,
                'rejected' => (int) $r->rejected,
            ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('daily_source_stats')->insert($chunk);
            }
        });
    }

    private function sums(): string
    {
        return 'COALESCE(SUM(count), 0) AS total, '
            .'COALESCE(SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN count ELSE 0 END), 0) AS dmarc_pass, '
            .'COALESCE(SUM(CASE WHEN dkim_aligned = 1 THEN count ELSE 0 END), 0) AS dkim_aligned, '
            .'COALESCE(SUM(CASE WHEN spf_aligned = 1 THEN count ELSE 0 END), 0) AS spf_aligned, '
            ."COALESCE(SUM(CASE WHEN disposition = 'quarantine' THEN count ELSE 0 END), 0) AS quarantined, "
            ."COALESCE(SUM(CASE WHEN disposition = 'reject' THEN count ELSE 0 END), 0) AS rejected";
    }
}
