<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Filament\App\Resources\Domains\DomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    protected static ?string $title = 'Add a domain';

    protected static bool $canCreateAnother = false;

    /** Go straight into the setup wizard. */
    protected function getRedirectUrl(): string
    {
        return DomainResource::getUrl('setup', ['record' => $this->getRecord()]);
    }
}
