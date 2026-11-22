<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Domain;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Models\Tenant;
use App\Services\DomainStats;
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
        $t = app(DomainStats::class)->totals(Domain::pluck('id')->all(), 30);

        return [
            Stat::make('Customers', (string) Tenant::count()),
            Stat::make('Domains', "{$live} / {$domains} live")
                ->description($silent ? "{$silent} silent for 72h+" : 'All active domains reporting')
                ->color($silent ? 'warning' : 'success'),
            Stat::make('Reports (24h)', number_format(Report::where('created_at', '>=', now()->subDay())->count()))
                ->description(number_format($t['total']).' messages in 30 days'),
            Stat::make('Ingest problems (7 days)', (string) $problems)
                ->description($problems ? 'See Ingest queue' : 'None')
                ->color($problems ? 'danger' : 'success'),
        ];
    }
}
