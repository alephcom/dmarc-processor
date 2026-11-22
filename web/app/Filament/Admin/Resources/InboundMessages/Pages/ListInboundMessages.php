<?php

namespace App\Filament\Admin\Resources\InboundMessages\Pages;

use App\Filament\Admin\Resources\InboundMessages\InboundMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInboundMessages extends ListRecords
{
    protected static string $resource = InboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
