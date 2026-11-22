<?php

namespace App\Filament\Admin\Resources\InboundMessages\Schemas;

use App\Filament\Admin\Resources\InboundMessages\Tables\InboundMessagesTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InboundMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('status')->badge()->color(fn (string $state) => InboundMessagesTable::statusColor($state)),
                TextEntry::make('domain.name')->label('Domain')->placeholder('Unknown token'),
                TextEntry::make('rua_token')->label('Token')->fontFamily('mono'),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('processed_at')->dateTime()->placeholder('—'),
                TextEntry::make('reports_count')->label('Reports imported'),
                TextEntry::make('mail_from')->label('Envelope sender')->placeholder('—'),
                TextEntry::make('remote_ip')->label('Sending server')->placeholder('—'),
                TextEntry::make('size')->formatStateUsing(fn (int $state) => number_format($state / 1024, 1).' KB'),
                TextEntry::make('subject')->columnSpanFull()->placeholder('—'),
                TextEntry::make('error')->columnSpanFull()->placeholder('No errors')->color('danger'),
                TextEntry::make('spool_id')->label('Spool ID')->fontFamily('mono')->color('gray'),
                TextEntry::make('raw_path')->label('Stored at')->fontFamily('mono')->color('gray'),
            ])->columns(3),
        ]);
    }
}
