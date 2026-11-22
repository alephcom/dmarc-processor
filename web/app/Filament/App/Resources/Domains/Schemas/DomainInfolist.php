<?php

namespace App\Filament\App\Resources\Domains\Schemas;

use App\Models\Domain;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DomainInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reporting & DNS')
                ->description('Mailbox providers send daily DMARC reports to this address.')
                ->collapsible()
                ->schema([
                    TextEntry::make('rua_address')
                        ->label('Reporting address')
                        ->state(fn (Domain $record) => $record->ruaAddress())
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('dns_verified_at')
                        ->label('DMARC record')
                        ->state(fn (Domain $record) => $record->dns_verified_at ? 'Live since '.$record->dns_verified_at->toFormattedDateString() : 'Not verified yet')
                        ->badge()
                        ->color(fn (Domain $record) => $record->dns_verified_at ? 'success' : 'warning')
                        ->helperText(fn (Domain $record) => $record->dns_checked_at ? 'Last checked '.$record->dns_checked_at->diffForHumans() : 'Not checked yet'),
                ])->columns(1),
            Section::make('Status')->collapsible()->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('current_policy')->label('Published policy')->placeholder('Not checked yet'),
                TextEntry::make('target_policy')->label('Target policy'),
                TextEntry::make('first_report_at')->label('First report')->dateTime()->placeholder('Waiting…'),
                TextEntry::make('last_report_at')->label('Last report')->since()->placeholder('Waiting…'),
            ])->columns(3),
        ]);
    }
}
