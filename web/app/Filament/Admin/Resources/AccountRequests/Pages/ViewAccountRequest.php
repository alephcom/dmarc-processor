<?php

namespace App\Filament\Admin\Resources\AccountRequests\Pages;

use App\Filament\Admin\Resources\AccountRequests\AccountRequestResource;
use App\Filament\Admin\Resources\AccountRequests\Tables\AccountRequestsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountRequest extends ViewRecord
{
    protected static string $resource = AccountRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [AccountRequestsTable::approveAction(), AccountRequestsTable::rejectAction()];
    }
}
