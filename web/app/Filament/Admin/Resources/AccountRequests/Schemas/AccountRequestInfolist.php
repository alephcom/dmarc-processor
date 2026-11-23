<?php

namespace App\Filament\Admin\Resources\AccountRequests\Schemas;

use App\Filament\Admin\Resources\AccountRequests\Tables\AccountRequestsTable;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\AccountRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextEntry::make('organisation'),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => AccountRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => AccountRequestsTable::statusColor($state)),
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('domains')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('message')->columnSpanFull()->placeholder('—')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('created_at')->label('Received')->dateTime(),
                TextEntry::make('ip')->label('IP address')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Reviewed')->dateTime()->placeholder('Not yet')
                    ->helperText(fn (AccountRequest $r) => $r->reviewer ? 'by '.$r->reviewer->name : null),
                TextEntry::make('tenant.name')->label('Organisation created')->placeholder('—')
                    ->url(fn (AccountRequest $r) => $r->tenant ? TenantResource::getUrl('edit', ['record' => $r->tenant]) : null),
            ]),
        ]);
    }
}
