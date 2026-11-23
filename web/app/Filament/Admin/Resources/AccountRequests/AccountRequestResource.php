<?php

namespace App\Filament\Admin\Resources\AccountRequests;

use App\Filament\Admin\Resources\AccountRequests\Pages\ListAccountRequests;
use App\Filament\Admin\Resources\AccountRequests\Pages\ViewAccountRequest;
use App\Filament\Admin\Resources\AccountRequests\Schemas\AccountRequestInfolist;
use App\Filament\Admin\Resources\AccountRequests\Tables\AccountRequestsTable;
use App\Models\AccountRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Free account requests from the public site, waiting for a super-admin to approve or decline. */
class AccountRequestResource extends Resource
{
    protected static ?string $model = AccountRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $recordTitleAttribute = 'organisation';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = AccountRequest::pending()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountRequests::route('/'),
            'view' => ViewAccountRequest::route('/{record}'),
        ];
    }
}
