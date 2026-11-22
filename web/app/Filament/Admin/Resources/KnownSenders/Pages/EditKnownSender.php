<?php

namespace App\Filament\Admin\Resources\KnownSenders\Pages;

use App\Filament\Admin\Resources\KnownSenders\KnownSenderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;

class EditKnownSender extends EditRecord
{
    protected static string $resource = KnownSenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        Artisan::queue('dmarc:reidentify-sources');
    }
}
