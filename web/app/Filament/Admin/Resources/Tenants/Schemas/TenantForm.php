<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Models\Tenant;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->required()->maxLength(64)->alphaDash()
                ->unique(Tenant::class, 'slug', ignoreRecord: true)
                ->helperText('Used in URLs: /app/{slug}'),
            TextInput::make('retention_days_raw')->label('Raw data retention (days)')
                ->numeric()->minValue(30)->maxValue(3650)->default(395)->required()
                ->helperText('Detailed report records older than this are deleted. Daily summaries are kept forever.'),
            Toggle::make('require_mfa')->label('Require two-factor authentication for this tenant\'s users'),
        ]);
    }
}
