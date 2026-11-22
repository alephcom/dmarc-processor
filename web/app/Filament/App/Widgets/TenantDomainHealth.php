<?php

namespace App\Filament\App\Widgets;

use App\Filament\Widgets\DomainHealthTable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class TenantDomainHealth extends DomainHealthTable
{
    protected function scope(Builder $query): Builder
    {
        // Widget queries are not tenant-scoped automatically; scope explicitly.
        return $query->where('domains.tenant_id', Filament::getTenant()?->getKey() ?? 0);
    }
}
