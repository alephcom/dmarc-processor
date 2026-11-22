<?php

namespace App\Intel;

use App\Alerts\AlertManager;
use App\Models\DomainSource;
use App\Models\IpIntel;
use Illuminate\Support\Facades\DB;

/**
 * Keeps domain_sources (one row per sender/network/IP a domain has seen, holding the user's
 * classification) in step with daily_source_stats.
 */
class SourceRegistry
{
    /** Re-key an IP's stats after its identification changed, carrying classifications across. */
    public function rekeyIp(string $ip): void
    {
        $intel = IpIntel::with('knownSender')->firstWhere('ip', $ip);
        $newKey = SourceKey::for($ip, $intel);

        $changed = DB::table('daily_source_stats')->where('source_ip', $ip)->where('source_key', '!=', $newKey)
            ->select('domain_id', 'source_key')->distinct()->get();
        if ($changed->isEmpty()) {
            return;
        }

        DB::table('daily_source_stats')->where('source_ip', $ip)->update(['source_key' => $newKey]);

        foreach ($changed->groupBy('domain_id') as $domainId => $rows) {
            $this->sync((int) $domainId);
            // The sender now has a better identity; a "new sender" alert raised under the old key is superseded.
            foreach ($rows->pluck('source_key') as $oldKey) {
                app(AlertManager::class)->resolveKey("new_sender:{$domainId}:{$oldKey}");
            }
            // If the user had already classified the old grouping, keep that decision.
            $target = DomainSource::where('domain_id', $domainId)->where('source_key', $newKey)->first();
            if ($target && $target->classification === 'unknown') {
                $old = DomainSource::where('domain_id', $domainId)->whereIn('source_key', $rows->pluck('source_key'))
                    ->where('classification', '!=', 'unknown')->first();
                if ($old) {
                    $target->update(['classification' => $old->classification]);
                }
            }
        }
    }

    /** Create domain_sources rows for any source keys the domain has stats for but no row yet; refresh labels. */
    public function sync(int $domainId): void
    {
        $keys = DB::table('daily_source_stats')->where('domain_id', $domainId)
            ->groupBy('source_key')
            ->selectRaw('source_key, MIN(day) AS first_day, MIN(source_ip) AS sample_ip')
            ->get();

        $existing = DomainSource::where('domain_id', $domainId)->pluck('label', 'source_key');
        foreach ($keys as $k) {
            $intel = IpIntel::with('knownSender')->firstWhere('ip', $k->sample_ip);
            $label = SourceKey::label($k->source_key, $intel);
            if (! $existing->has($k->source_key)) {
                DomainSource::create([
                    'domain_id' => $domainId,
                    'source_key' => $k->source_key,
                    'label' => $label,
                    'classification' => 'unknown',
                    'first_seen_at' => $k->first_day,
                ]);
            } elseif ($existing[$k->source_key] !== $label) {
                DomainSource::where('domain_id', $domainId)->where('source_key', $k->source_key)->update(['label' => $label]);
            }
        }
    }
}
