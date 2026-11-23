<?php

namespace App\Filament\Admin\Resources\AccountRequests\Pages;

use App\Filament\Admin\Resources\AccountRequests\AccountRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountRequests extends ListRecords
{
    protected static string $resource = AccountRequestResource::class;
}
