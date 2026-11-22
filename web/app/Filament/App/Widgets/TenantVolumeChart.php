<?php

namespace App\Filament\App\Widgets;

use App\Filament\Widgets\PassFailChart;
use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class TenantVolumeChart extends ChartWidget
{
    protected static ?int $sort = 2;

    /** An all-zero chart says nothing: hidden until the first report, while {@see GettingStarted} explains the wait. */
    public static function canView(): bool
    {
        return GettingStarted::tenantHasReports();
    }

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $heading = 'Mail sent as your domains';

    protected ?string $description = 'Last 30 days, all domains, by DMARC result.';

    protected function getData(): array
    {
        $ids = Domain::where('tenant_id', Filament::getTenant()?->getKey() ?? 0)->pluck('id')->all();

        return PassFailChart::data(app(DomainStats::class)->daily($ids, 30));
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
