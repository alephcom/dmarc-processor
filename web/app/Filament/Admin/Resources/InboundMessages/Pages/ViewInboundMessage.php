<?php

namespace App\Filament\Admin\Resources\InboundMessages\Pages;

use App\Filament\Admin\Resources\InboundMessages\InboundMessageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundMessage extends ViewRecord
{
    protected static string $resource = InboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
