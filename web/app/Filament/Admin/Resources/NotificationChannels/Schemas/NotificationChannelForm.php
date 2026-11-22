<?php

namespace App\Filament\Admin\Resources\NotificationChannels\Schemas;

use App\Filament\Shared\ChannelSchema;
use Filament\Schemas\Schema;

class NotificationChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return ChannelSchema::form($schema, msp: true);
    }
}
