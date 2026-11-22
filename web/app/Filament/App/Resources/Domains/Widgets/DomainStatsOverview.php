<?php

namespace App\Filament\App\Resources\Domains\Widgets;

use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DomainStatsOverview extends StatsOverviewWidget
{
    public ?Domain $record = null;

    public int $days = 30;

    protected function getStats(): array
    {
        $stats = app(DomainStats::class);
        $t = $stats->totals($this->record->id, $this->days);
        $pass = DomainStats::rate($t['dmarc_pass'], $t['total']);
        $unknown = $stats->unknownFailingSources($this->record->id, $this->days);
        $period = strtolower(DomainStats::PERIODS[$this->days]);

        return [
            Stat::make('Messages reported', number_format($t['total']))
                ->description($t['total'] ? $period : 'No reports in this period yet'),
            Stat::make('Passing DMARC', $pass === null ? '—' : $pass.'%')
                ->description($t['total'] ? number_format($t['total'] - $t['dmarc_pass']).' failing' : 'Waiting for data')
                ->descriptionIcon($pass !== null && $pass >= 98 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($pass === null ? 'gray' : ($pass >= 98 ? 'success' : ($pass >= 90 ? 'warning' : 'danger'))),
            Stat::make('Senders to review', (string) $unknown)
                ->description($unknown ? 'Failing and not marked legitimate' : 'Nothing needs review')
                ->descriptionIcon($unknown ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($unknown ? 'warning' : 'success'),
            Stat::make('Policy', $this->record->current_policy ? 'p='.$this->record->current_policy.($this->record->current_pct && $this->record->current_pct < 100 ? " ({$this->record->current_pct}%)" : '') : '—')
                ->description('Target: p='.$this->record->target_policy),
        ];
    }
}
