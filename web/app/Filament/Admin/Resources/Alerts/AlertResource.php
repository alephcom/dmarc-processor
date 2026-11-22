<?php

namespace App\Filament\Admin\Resources\Alerts;

use App\Filament\Admin\Resources\Alerts\Pages\ListAlerts;
use App\Filament\Admin\Resources\Alerts\Pages\ViewAlert;
use App\Filament\Admin\Resources\Alerts\Schemas\AlertInfolist;
use App\Filament\Admin\Resources\Alerts\Tables\AlertsTable;
use App\Models\Alert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Alert::open()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return AlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlerts::route('/'),
            'view' => ViewAlert::route('/{record}'),
        ];
    }
}
