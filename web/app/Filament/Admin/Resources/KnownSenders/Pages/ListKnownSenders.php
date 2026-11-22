<?php

namespace App\Filament\Admin\Resources\KnownSenders\Pages;

use App\Filament\Admin\Resources\KnownSenders\KnownSenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnownSenders extends ListRecords
{
    protected static string $resource = KnownSenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
