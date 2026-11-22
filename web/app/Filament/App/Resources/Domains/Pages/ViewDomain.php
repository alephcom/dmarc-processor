<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\App\Resources\Domains\Widgets\DomainReportersTable;
use App\Filament\App\Resources\Domains\Widgets\DomainSourcesTable;
use App\Filament\App\Resources\Domains\Widgets\DomainStatsOverview;
use App\Filament\App\Resources\Domains\Widgets\DomainVolumeChart;
use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    /** Reporting period in days; kept in the URL so links and reloads keep it. */
    #[Url(as: 'period')]
    public int $days = 30;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->days = DomainStats::normalisePeriod($this->days);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(array_map(
                fn (int $days, string $label) => Action::make('period'.$days)
                    ->label($label)
                    ->icon($days === $this->days ? Heroicon::Check : null)
                    ->url(DomainResource::getUrl('view', ['record' => $this->record]).'?period='.$days),
                array_keys(DomainStats::PERIODS),
                DomainStats::PERIODS,
            ))
                ->label(DomainStats::PERIODS[$this->days])
                ->icon(Heroicon::OutlinedCalendar)
                ->button()
                ->color('gray'),
            Action::make('setup')
                ->label(fn (Domain $record) => $record->isOnboarding() ? 'Continue setup' : 'Setup wizard')
                ->icon(Heroicon::OutlinedSparkles)
                ->color(fn (Domain $record) => $record->isOnboarding() ? 'primary' : 'gray')
                ->url(fn (Domain $record) => DomainResource::getUrl('setup', ['record' => $record]))
                ->visible(fn (Domain $record) => DomainResource::canEdit($record)),
            EditAction::make()->color('gray'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [DomainStatsOverview::class, DomainVolumeChart::class];
    }

    protected function getFooterWidgets(): array
    {
        return [DomainSourcesTable::class, DomainReportersTable::class];
    }

    public function getWidgetData(): array
    {
        return [...parent::getWidgetData(), 'days' => $this->days];
    }
}
