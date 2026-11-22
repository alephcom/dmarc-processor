<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(User::class, 'email', ignoreRecord: true),
            Toggle::make('is_super_admin')->label('MSP super-admin')
                ->helperText('Full access to every customer and to MSP settings. Super-admins must use two-factor authentication.')
                ->disabled(fn (?User $record) => $record?->id === auth()->id())
                ->dehydrated(fn (?User $record) => $record?->id !== auth()->id()),
        ]);
    }
}
