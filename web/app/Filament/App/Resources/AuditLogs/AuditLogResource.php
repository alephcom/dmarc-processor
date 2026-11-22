<?php

namespace App\Filament\App\Resources\AuditLogs;

use App\Filament\App\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Shared\AuditTable;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?string $slug = 'activity';

    public static function table(Table $table): Table
    {
        return AuditTable::configure($table, msp: false);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
