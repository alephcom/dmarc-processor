<?php

namespace App\Filament\App\Resources\Domains\Widgets;

use App\Filament\Widgets\PassFailChart;
use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class DomainVolumeChart extends ChartWidget
{
    public ?Domain $record = null;

    public int $days = 30;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return 'Mail sent as '.$this->record->name;
    }

    public function getDescription(): ?string
    {
        return 'Messages per '.($this->days > 120 ? 'week' : 'day').' reported by receiving mail servers, by DMARC result.';
    }

    protected function getData(): array
    {
        return PassFailChart::data(app(DomainStats::class)->daily($this->record->id, $this->days));
    }

    protected function getOptions(): RawJs
    {
        return PassFailChart::options();
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
