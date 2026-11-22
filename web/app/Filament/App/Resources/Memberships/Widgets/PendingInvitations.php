<?php

namespace App\Filament\App\Resources\Memberships\Widgets;

use App\Filament\Shared\TeamTable;
use App\Models\Invitation;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingInvitations extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // Widget queries aren't tenant-scoped automatically.
        return TeamTable::invitations($table->query(fn () => Invitation::pending()->with('inviter')
            ->where('tenant_id', Filament::getTenant()?->getKey() ?? 0)));
    }
}
