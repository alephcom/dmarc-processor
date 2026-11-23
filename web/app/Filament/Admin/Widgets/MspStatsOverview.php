<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Widgets\StatTrends;
use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\Tenant;
use App\Services\DomainStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MspStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $domains = Domain::count();
        $live = Domain::whereNotNull('dns_verified_at')->count();
        $silent = Domain::where('status', Domain::STATUS_ACTIVE)->where('last_report_at', '<', now()->subHours(72))->count();
        $problems = InboundMessage::whereIn('status', [InboundMessage::STATUS_FAILED, InboundMessage::STATUS_QUARANTINED])
            ->where('created_at', '>=', now()->subDays(7))->count();
        $ids = Domain::pluck('id')->all();
        $stats = app(DomainStats::class);
        $t = $stats->totals($ids, 30);
        $daily = $stats->daily($ids, 30);
        $previous = $stats->previousTotals($ids, 30);

        return [
            Stat::make('Customers', (string) Tenant::count()),
            Stat::make('Domains', "{$live} / {$domains} live")
                ->description($silent ? "{$silent} silent for 72h+" : 'All active domains reporting')
                ->descriptionIcon($silent ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
                ->color($silent ? 'warning' : 'success'),
            StatTrends::volume('Messages (30 days)', $daily, $t['total'], $previous['total']),
            StatTrends::passRate($daily, $t, $previous),
            Stat::make('Reports (24h)', number_format(Report::where('created_at', '>=', now()->subDay())->count())),
            Stat::make('Ingest problems (7 days)', (string) $problems)
                ->description($problems ? 'See Ingest queue' : 'None')
                ->descriptionIcon($problems ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
                ->color($problems ? 'danger' : 'success'),
        ];
    }
}
