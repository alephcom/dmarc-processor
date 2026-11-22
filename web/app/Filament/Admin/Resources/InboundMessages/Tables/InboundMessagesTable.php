<?php

namespace App\Filament\Admin\Resources\InboundMessages\Tables;

use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InboundMessagesTable
{
    public static function statusColor(string $state): string
    {
        return match ($state) {
            InboundMessage::STATUS_PROCESSED => 'success',
            InboundMessage::STATUS_IGNORED => 'gray',
            InboundMessage::STATUS_PENDING => 'info',
            default => 'danger',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('domain.name')->label('Domain')->placeholder('Unknown token')->searchable(),
                TextColumn::make('domain.tenant.name')->label('Tenant')->toggleable(),
                TextColumn::make('mail_from')->label('From')->limit(40)->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('reports_count')->label('Reports'),
                TextColumn::make('error')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    InboundMessage::STATUS_PENDING => 'Pending',
                    InboundMessage::STATUS_PROCESSED => 'Processed',
                    InboundMessage::STATUS_IGNORED => 'Ignored',
                    InboundMessage::STATUS_QUARANTINED => 'Quarantined',
                    InboundMessage::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->defaultSort('received_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('reprocess')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (InboundMessage $record) => $record->status === InboundMessage::STATUS_FAILED && $record->domain_id)
                    ->requiresConfirmation()
                    ->action(function (InboundMessage $record) {
                        ProcessInboundMessage::dispatch($record->id);
                        Notification::make()->title('Queued for reprocessing')->success()->send();
                    }),
            ]);
    }
}
