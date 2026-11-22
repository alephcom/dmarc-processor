<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Widgets\DomainHealthTable;
use Illuminate\Database\Eloquent\Builder;

class AllDomainsHealth extends DomainHealthTable
{
    protected function scope(Builder $query): Builder
    {
        return $query;
    }

    protected function showTenant(): bool
    {
        return true;
    }
}
