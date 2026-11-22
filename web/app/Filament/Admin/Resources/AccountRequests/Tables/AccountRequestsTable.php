<?php

namespace App\Filament\Admin\Resources\AccountRequests\Tables;

use App\AccountRequests\AccountRequestService;
use App\Models\AccountRequest;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class AccountRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organisation')->searchable()->weight('medium')
                    ->description(fn (AccountRequest $r) => $r->name),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('domains')->limit(60)->wrap(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => AccountRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(AccountRequest::STATUSES)->default(AccountRequest::PENDING)])
            ->defaultSort('created_at')
            ->recordActions([ViewAction::make(), self::approveAction(), self::rejectAction()])
            ->emptyStateHeading('No account requests')
            ->emptyStateDescription('Requests from the public sign-up form appear here.');
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            AccountRequest::APPROVED => 'success',
            AccountRequest::REJECTED => 'gray',
            default => 'warning',
        };
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (AccountRequest $record) => $record->isPending())
            ->requiresConfirmation()
            ->modalHeading(fn (AccountRequest $record) => "Approve {$record->organisation}?")
            ->modalDescription(fn (AccountRequest $record) => "This creates the organisation and emails {$record->email} an invitation to join as owner.")
            ->action(function (AccountRequest $record) {
                try {
                    $tenant = app(AccountRequestService::class)->approve($record, auth()->user());
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title("Approved. Invitation sent to {$record->email}")->body("Organisation: {$tenant->name}")->success()->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Decline')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (AccountRequest $record) => $record->isPending())
            ->modalHeading(fn (AccountRequest $record) => "Decline {$record->organisation}?")
            ->schema([
                Toggle::make('notify')->label('Email the requester')->default(true)
                    ->helperText('They get a short note pointing them to the self-hosting instructions.'),
                Textarea::make('note')->label('Note to include (optional)')->maxLength(1000)->rows(3),
            ])
            ->action(function (AccountRequest $record, array $data) {
                try {
                    app(AccountRequestService::class)->reject($record, auth()->user(), (bool) $data['notify'], $data['note'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Request declined')->success()->send();
            });
    }
}
