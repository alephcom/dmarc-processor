<?php

namespace App\Filament\App\Resources\Alerts\Schemas;

use App\Filament\Shared\AlertSchema;
use Filament\Schemas\Schema;

class AlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return AlertSchema::infolist($schema);
    }
}
