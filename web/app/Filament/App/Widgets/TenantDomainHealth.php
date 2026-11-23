<?php

namespace App\Filament\App\Widgets;

use App\Filament\Shared\ReportActions;
use App\Filament\Support\CsvExport;
use App\Filament\Widgets\DomainHealthTable;
use App\Reports\ShareLinks;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantDomainHealth extends DomainHealthTable
{
    /** Hidden on an empty workspace, where {@see GettingStarted} takes over. */
    public static function canView(): bool
    {
        return GettingStarted::tenantHasDomains();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->headerActions([
            CsvExport::action(fn () => Filament::getTenant()->slug.'-domains'),
            ReportActions::group(
                fn () => route('reports.tenant', ['tenant' => Filament::getTenant(), 'period' => 30]),
                fn () => ShareLinks::tenant(Filament::getTenant(), 30),
            ),
        ]);
    }

    protected function scope(Builder $query): Builder
    {
        // Widget queries are not tenant-scoped automatically; scope explicitly.
        return $query->where('domains.tenant_id', Filament::getTenant()?->getKey() ?? 0);
    }
}
