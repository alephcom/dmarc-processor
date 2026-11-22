<?php

namespace App\Filament\Admin\Resources\NotificationChannels\Tables;

use App\Filament\Shared\ChannelSchema;
use Filament\Tables\Table;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return ChannelSchema::table($table);
    }
}
