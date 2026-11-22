<?php

namespace App\Filament\App\Resources\Domains;

use App\Filament\App\Resources\Domains\Pages\CreateDomain;
use App\Filament\App\Resources\Domains\Pages\EditDomain;
use App\Filament\App\Resources\Domains\Pages\ListDomains;
use App\Filament\App\Resources\Domains\Pages\SetupDomain;
use App\Filament\App\Resources\Domains\Pages\ViewDomain;
use App\Filament\App\Resources\Domains\Schemas\DomainForm;
use App\Filament\App\Resources\Domains\Schemas\DomainInfolist;
use App\Filament\App\Resources\Domains\Tables\DomainsTable;
use App\Models\Domain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DomainForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DomainInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
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
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
            'edit' => EditDomain::route('/{record}/edit'),
            'setup' => SetupDomain::route('/{record}/setup'),
        ];
    }
}
