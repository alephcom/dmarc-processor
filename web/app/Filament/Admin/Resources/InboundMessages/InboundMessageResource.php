<?php

namespace App\Filament\Admin\Resources\InboundMessages;

use App\Filament\Admin\Resources\InboundMessages\Pages\ListInboundMessages;
use App\Filament\Admin\Resources\InboundMessages\Pages\ViewInboundMessage;
use App\Filament\Admin\Resources\InboundMessages\Schemas\InboundMessageInfolist;
use App\Filament\Admin\Resources\InboundMessages\Tables\InboundMessagesTable;
use App\Models\InboundMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InboundMessageResource extends Resource
{
    protected static ?string $model = InboundMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Ingest queue';

    protected static ?string $modelLabel = 'inbound message';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = InboundMessage::whereIn('status', [InboundMessage::STATUS_FAILED, InboundMessage::STATUS_QUARANTINED])->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return InboundMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InboundMessagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundMessages::route('/'),
            'view' => ViewInboundMessage::route('/{record}'),
        ];
    }
}
