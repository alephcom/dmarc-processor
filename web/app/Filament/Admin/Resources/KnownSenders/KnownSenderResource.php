<?php

namespace App\Filament\Admin\Resources\KnownSenders;

use App\Filament\Admin\Resources\KnownSenders\Pages\CreateKnownSender;
use App\Filament\Admin\Resources\KnownSenders\Pages\EditKnownSender;
use App\Filament\Admin\Resources\KnownSenders\Pages\ListKnownSenders;
use App\Filament\Admin\Resources\KnownSenders\Schemas\KnownSenderForm;
use App\Filament\Admin\Resources\KnownSenders\Tables\KnownSendersTable;
use App\Models\KnownSender;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KnownSenderResource extends Resource
{
    protected static ?string $model = KnownSender::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return KnownSenderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnownSendersTable::configure($table);
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
            'index' => ListKnownSenders::route('/'),
            'create' => CreateKnownSender::route('/create'),
            'edit' => EditKnownSender::route('/{record}/edit'),
        ];
    }
}
