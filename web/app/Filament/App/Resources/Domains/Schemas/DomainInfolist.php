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
            Section::make('Reporting')
                ->description('Mailbox providers send daily DMARC reports to this address.')
                ->schema([
                    TextEntry::make('rua_address')
                        ->label('Reporting address')
                        ->state(fn (Domain $record) => $record->ruaAddress())
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('suggested_record')
                        ->label(fn (Domain $record) => "Starter DMARC record (TXT at _dmarc.{$record->name})")
                        ->state(fn (Domain $record) => 'v=DMARC1; p=none; rua=mailto:'.$record->ruaAddress().'; fo=1')
                        ->helperText('The setup wizard (coming next) will check your existing record and merge this for you.')
                        ->copyable()
                        ->fontFamily('mono'),
                ])->columns(1),
            Section::make('Status')->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('current_policy')->label('Published policy')->placeholder('Not checked yet'),
                TextEntry::make('target_policy')->label('Target policy'),
                TextEntry::make('first_report_at')->label('First report')->dateTime()->placeholder('Waiting…'),
                TextEntry::make('last_report_at')->label('Last report')->since()->placeholder('Waiting…'),
            ])->columns(3),
        ]);
    }
}
