<?php

namespace App\Filament\App\Resources\Domains\Schemas;

use App\Models\Domain;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DomainForm
{
    public const DOMAIN_REGEX = '/^(?=.{4,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/';

    public static function normalise(?string $name): string
    {
        return strtolower(rtrim(trim((string) $name), '.'));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Domain')
                ->placeholder('example.com')
                ->helperText('The domain that appears in the From: address of your email.')
                ->required()
                ->disabledOn('edit')
                ->mutateStateForValidationUsing(fn (?string $state) => self::normalise($state))
                ->dehydrateStateUsing(fn (?string $state) => self::normalise($state))
                ->regex(self::DOMAIN_REGEX)
                ->unique(Domain::class, 'name', ignoreRecord: true)
                ->validationMessages(['unique' => 'This domain is already being monitored. Contact your MSP if you think this is a mistake.']),
            Select::make('target_policy')
                ->label('Target policy')
                ->options(['quarantine' => 'Quarantine', 'reject' => 'Reject (recommended)'])
                ->default('reject')
                ->required()
                ->visibleOn('edit'),
            TagsInput::make('dkim_selectors')
                ->label('Extra DKIM selectors to check')
                ->helperText('We automatically check common selectors (google, selector1, selector2, k1, s1, ...). Add any others your senders use.')
                ->visibleOn('edit'),
        ]);
    }
}
