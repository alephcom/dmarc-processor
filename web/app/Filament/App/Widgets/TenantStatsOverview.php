<?php

namespace App\Filament\App\Widgets;

use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class TenantStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $domains = Domain::where('tenant_id', Filament::getTenant()?->getKey() ?? 0)->get(['id', 'dns_verified_at']);
        $ids = $domains->pluck('id')->all();
        $t = app(DomainStats::class)->totals($ids, 30);
        $pass = DomainStats::rate($t['dmarc_pass'], $t['total']);
        $below = DB::table('daily_domain_stats')->whereIn('domain_id', $ids)->where('day', '>=', DomainStats::from(30))
            ->groupBy('domain_id')->havingRaw('SUM(dmarc_pass) < 0.98 * SUM(total)')->select('domain_id')->get()->count();
        $live = $domains->whereNotNull('dns_verified_at')->count();

        return [
            Stat::make('Domains', (string) $domains->count())
                ->description($live === $domains->count() ? 'All DMARC records live' : ($domains->count() - $live).' not set up yet')
                ->color($live === $domains->count() ? 'success' : 'warning'),
            Stat::make('Messages (30 days)', number_format($t['total'])),
            Stat::make('Passing DMARC', $pass === null ? '—' : $pass.'%')
                ->color($pass === null ? 'gray' : ($pass >= 98 ? 'success' : ($pass >= 90 ? 'warning' : 'danger'))),
            Stat::make('Domains needing attention', (string) $below)
                ->description('Below 98% passing')
                ->color($below ? 'warning' : 'success'),
        ];
    }
}
