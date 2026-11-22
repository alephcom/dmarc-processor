<?php

namespace App\Filament\App\Resources\Memberships\Pages;

use App\Filament\App\Resources\Memberships\MembershipResource;
use App\Filament\App\Resources\Memberships\Widgets\PendingInvitations;
use Filament\Resources\Pages\ListRecords;

class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;

    protected ?string $heading = 'Team';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [PendingInvitations::class];
    }
}
