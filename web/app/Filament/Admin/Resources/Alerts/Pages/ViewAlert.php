<?php

namespace App\Filament\Admin\Resources\Alerts\Pages;

use App\Filament\Admin\Resources\Alerts\AlertResource;
use App\Filament\Shared\AlertSchema;
use Filament\Resources\Pages\ViewRecord;

class ViewAlert extends ViewRecord
{
    protected static string $resource = AlertResource::class;

    protected function getHeaderActions(): array
    {
        return [AlertSchema::resolveAction()];
    }
}
