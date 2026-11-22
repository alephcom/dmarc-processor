<?php

namespace App\Filament\App\Resources\Memberships;

use App\Filament\App\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Shared\TeamTable;
use App\Models\Membership;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $modelLabel = 'team member';

    protected static ?string $pluralModelLabel = 'team';

    protected static ?string $slug = 'team';

    public static function table(Table $table): Table
    {
        return TeamTable::configure($table, fn () => Filament::getTenant());
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberships::route('/')];
    }
}
