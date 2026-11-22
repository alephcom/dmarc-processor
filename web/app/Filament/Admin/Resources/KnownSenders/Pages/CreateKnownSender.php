<?php

namespace App\Filament\Admin\Resources\KnownSenders\Pages;

use App\Filament\Admin\Resources\KnownSenders\KnownSenderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Artisan;

class CreateKnownSender extends CreateRecord
{
    protected static string $resource = KnownSenderResource::class;

    protected function afterCreate(): void
    {
        Artisan::queue('dmarc:reidentify-sources');
    }
}
