<?php

namespace App\Filament\Shared;

use App\Alerts\AlertManager;
use App\Alerts\AlertType;
use App\Alerts\Severity;
use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class AlertSchema
{
    public static function table(Table $table, bool $msp): Table
    {
        return $table
            ->columns([
                TextColumn::make('severity')->badge()->color(fn (string $state) => Severity::color($state))
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('title')->weight('medium')->wrap()->searchable()
                    ->description(fn (Alert $r) => $r->alertType()->label()),
                TextColumn::make('tenant.name')->label('Customer')->placeholder('Platform')->visible($msp),
                TextColumn::make('domain.name')->label('Domain')->placeholder('—'),
                TextColumn::make('opened_at')->label('Opened')->since()->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (Alert $r) => $r->isOpen() ? 'Open' : 'Resolved')
                    ->color(fn (Alert $r) => $r->isOpen() ? 'warning' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('open')->label('Status')->placeholder('All')->trueLabel('Open')->falseLabel('Resolved')
                    ->queries(true: fn ($q) => $q->whereNull('resolved_at'), false: fn ($q) => $q->whereNotNull('resolved_at'))
                    ->default(true),
                SelectFilter::make('type')->options(AlertType::options(includeMsp: $msp)),
                SelectFilter::make('severity')->options(Severity::OPTIONS),
            ])
            ->defaultSort('opened_at', 'desc')
            ->recordActions([ViewAction::make(), self::resolveAction()])
            ->emptyStateHeading('No alerts')
            ->emptyStateDescription('Nothing needs your attention.');
    }

    public static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label('Mark resolved')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('It will open again if the problem is detected again.')
            ->visible(fn (Alert $record) => $record->isOpen() && auth()->user()->can('update', $record))
            ->action(fn (Alert $record) => app(AlertManager::class)->resolve($record, 'manual', auth()->id()));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('severity')->badge()->color(fn (string $state) => Severity::color($state))->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextEntry::make('type')->formatStateUsing(fn (string $state) => AlertType::from($state)->label()),
                TextEntry::make('domain.name')->label('Domain')->placeholder('—'),
                TextEntry::make('body')->label('Details')->columnSpanFull()->prose(),
                TextEntry::make('opened_at')->dateTime(),
                TextEntry::make('last_seen_at')->label('Last detected')->since(),
                TextEntry::make('resolved_at')->dateTime()->placeholder('Still open')
                    ->helperText(fn (Alert $r) => $r->resolution ? match ($r->resolution) {
                        'auto' => 'Resolved automatically when the problem cleared',
                        'expired' => 'Closed automatically after 7 days',
                        default => 'Marked resolved'.($r->resolver ? ' by '.$r->resolver->name : ''),
                    } : null),
            ]),
        ]);
    }
}
