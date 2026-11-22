<?php

namespace App\Filament\App\Widgets;

use App\Filament\Widgets\StatTrends;
use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class TenantStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** Hidden on an empty workspace, where {@see GettingStarted} takes over. */
    public static function canView(): bool
    {
        return GettingStarted::tenantHasDomains();
    }

    protected function getStats(): array
    {
        $domains = Domain::where('tenant_id', Filament::getTenant()?->getKey() ?? 0)->get(['id', 'dns_verified_at']);
        $ids = $domains->pluck('id')->all();
        $stats = app(DomainStats::class);
        $t = $stats->totals($ids, 30);
        $daily = $stats->daily($ids, 30);
        $previous = $stats->previousTotals($ids, 30);
        $below = DB::table('daily_domain_stats')->whereIn('domain_id', $ids)->where('day', '>=', DomainStats::from(30))
            ->groupBy('domain_id')->havingRaw('SUM(dmarc_pass) < 0.98 * SUM(total)')->select('domain_id')->get()->count();
        $live = $domains->whereNotNull('dns_verified_at')->count();

        $all = $live === $domains->count();

        return [
            Stat::make('Domains', (string) $domains->count())
                ->description($all ? 'All DMARC records live' : ($domains->count() - $live).' not set up yet')
                ->descriptionIcon($all ? Heroicon::CheckCircle : Heroicon::ExclamationTriangle)
                ->color($all ? 'success' : 'warning'),
            StatTrends::volume('Messages (30 days)', $daily, $t['total'], $previous['total']),
            StatTrends::passRate($daily, $t, $previous),
            Stat::make('Domains needing attention', (string) $below)
                ->description('Below 98% passing')
                ->descriptionIcon($below ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
                ->color($below ? 'warning' : 'success'),
        ];
    }
}
