<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setup')
                ->label(fn (Domain $record) => $record->isOnboarding() ? 'Continue setup' : 'Setup wizard')
                ->icon(Heroicon::OutlinedSparkles)
                ->color(fn (Domain $record) => $record->isOnboarding() ? 'primary' : 'gray')
                ->url(fn (Domain $record) => DomainResource::getUrl('setup', ['record' => $record]))
                ->visible(fn (Domain $record) => DomainResource::canEdit($record)),
            EditAction::make()->color('gray'),
        ];
    }
}
