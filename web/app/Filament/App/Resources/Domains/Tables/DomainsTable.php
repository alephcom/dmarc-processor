<?php

namespace App\Filament\App\Resources\Domains\Tables;

use App\Models\Domain;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Domain')->searchable()->sortable()->weight('medium'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    Domain::STATUS_ACTIVE => 'success',
                    Domain::STATUS_PAUSED => 'gray',
                    default => 'warning',
                })->formatStateUsing(fn (string $state) => $state === Domain::STATUS_PENDING ? 'Waiting for reports' : ucfirst($state)),
                TextColumn::make('dns_verified_at')->label('DMARC record')->badge()
                    ->state(fn (Domain $record) => $record->dns_verified_at ? 'Live' : ($record->dns_checked_at ? 'Not found' : 'Not checked'))
                    ->color(fn (Domain $record) => $record->dns_verified_at ? 'success' : 'warning'),
                TextColumn::make('current_policy')->label('Policy')->placeholder('—')->badge()->color('gray'),
                TextColumn::make('last_report_at')->label('Last report')->since()->placeholder('Never')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Domain::STATUS_PENDING => 'Waiting for reports',
                    Domain::STATUS_ACTIVE => 'Active',
                    Domain::STATUS_PAUSED => 'Paused',
                ]),
            ])
            ->defaultSort('name')
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
