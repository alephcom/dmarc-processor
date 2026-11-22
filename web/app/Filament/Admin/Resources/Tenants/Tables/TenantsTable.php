<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('domains_count')->counts('domains')->label('Domains')->sortable(),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                IconColumn::make('require_mfa')->label('2FA required')->boolean(),
                TextColumn::make('created_at')->date()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }
}
