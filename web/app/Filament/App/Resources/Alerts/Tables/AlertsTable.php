<?php

namespace App\Filament\App\Resources\Alerts\Tables;

use App\Filament\Shared\AlertSchema;
use Filament\Tables\Table;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return AlertSchema::table($table, msp: false);
    }
}
