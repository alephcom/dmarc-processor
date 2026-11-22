<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Filament\Shared\TeamTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return TeamTable::configure($table, fn () => $this->getOwnerRecord());
    }
}
