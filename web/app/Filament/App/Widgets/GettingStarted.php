<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Dashboard empty state: shown until the tenant has a domain that has received a report.
 * The other dashboard widgets hide themselves while there is nothing for them to show.
 */
class GettingStarted extends Widget
{
    protected static ?int $sort = 0;

    // Cheap to render, and the first thing a new user sees: no loading placeholder.
    protected static ?bool $isLazyLoaded = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.getting-started';

    public static function canView(): bool
    {
        return ! self::tenantHasReports();
    }

    /** True once the current tenant has at least one domain. */
    public static function tenantHasDomains(): bool
    {
        return Domain::where('tenant_id', Filament::getTenant()?->getKey() ?? 0)->exists();
    }

    /** True once any of the current tenant's domains has received a report. */
    public static function tenantHasReports(): bool
    {
        return Domain::where('tenant_id', Filament::getTenant()?->getKey() ?? 0)->whereNotNull('first_report_at')->exists();
    }

    protected function getViewData(): array
    {
        $tenantId = Filament::getTenant()?->getKey() ?? 0;
        // The first domain whose DMARC record isn't live yet: the most useful place to send someone.
        $unfinished = Domain::where('tenant_id', $tenantId)->whereNull('dns_verified_at')->orderBy('id')->first();

        return [
            'hasDomains' => self::tenantHasDomains(),
            'canCreate' => DomainResource::canCreate(),
            'createUrl' => DomainResource::getUrl('create'),
            'unfinished' => $unfinished,
            'setupUrl' => $unfinished && DomainResource::canEdit($unfinished)
                ? DomainResource::getUrl('setup', ['record' => $unfinished]) : null,
        ];
    }
}
