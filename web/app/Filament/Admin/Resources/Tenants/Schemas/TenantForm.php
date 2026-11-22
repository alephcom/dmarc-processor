<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
            Toggle::make('require_mfa')->label('Require two-factor authentication for this tenant\'s users')
                ->helperText('People who sign in with Microsoft or Google single sign-on use their provider\'s second factor instead.'),
            Section::make('Single sign-on')
                ->description('Let this customer\'s staff sign in with Microsoft 365 or Google Workspace. People from these email domains get an account automatically on first sign-in.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    TagsInput::make('sso_domains')->label('Email domains')
                        ->placeholder('acme.com')
                        ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'])
                        ->helperText('Google: only Workspace accounts whose verified domain matches. Microsoft: also requires a directory ID below.'),
                    TagsInput::make('sso_microsoft_tenant_ids')->label('Microsoft Entra directory (tenant) IDs')
                        ->placeholder('00000000-0000-0000-0000-000000000000')
                        ->nestedRecursiveRules(['uuid'])
                        ->helperText('Find it in the Entra admin centre under Overview → Tenant ID. Without it, Microsoft sign-in is not accepted for this customer.'),
                    Select::make('sso_default_role')->label('Role for new single sign-on users')
                        ->options(['viewer' => 'Viewer', 'admin' => 'Admin'])->default('viewer')->required(),
                ]),
        ]);
    }
}
