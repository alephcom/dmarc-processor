<?php

namespace App\Filament\App\Resources\NotificationChannels\Tables;

use App\Filament\Shared\ChannelSchema;
use Filament\Tables\Table;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return ChannelSchema::table($table);
    }
}
