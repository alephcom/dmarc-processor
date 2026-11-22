<?php

namespace App\Filament\Admin\Resources\KnownSenders\Tables;

use App\Filament\Admin\Resources\KnownSenders\Schemas\KnownSenderForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class KnownSendersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('category')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => KnownSenderForm::CATEGORIES[$state] ?? $state),
                TextColumn::make('rdns_suffixes')->label('Reverse DNS')->badge()->color('gray')->limitList(3),
                TextColumn::make('asns')->label('ASNs')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('ip_count')->label('IPs matched')->counts('ipIntel')->sortable(),
            ])
            ->filters([SelectFilter::make('category')->options(KnownSenderForm::CATEGORIES)])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
