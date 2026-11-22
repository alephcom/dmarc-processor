<?php

namespace App\Filament\Shared;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Team\TeamRuleException;
use App\Team\TeamService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Team members table with role changes, removal and invitations. Used by the tenant's Team page
 * and by the Members tab on a tenant in the MSP admin panel. All changes go through TeamService.
 */
final class TeamTable
{
    /** @param Closure(): Tenant $tenant */
    public static function configure(Table $table, Closure $tenant): Table
    {
        $team = app(TeamService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->weight('medium')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable()->copyable(),
                TextColumn::make('role')->badge()
                    ->formatStateUsing(fn (string $state) => TeamService::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => ['owner' => 'primary', 'admin' => 'info'][$state] ?? 'gray'),
                IconColumn::make('mfa')->label('2FA')->boolean()
                    ->state(fn (Membership $m) => (bool) $m->user?->app_authentication_secret),
                TextColumn::make('created_at')->label('Joined')->date()->sortable(),
            ])
            ->defaultSort('created_at')
            ->headerActions([
                Action::make('invite')
                    ->label('Invite someone')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn () => $team->assignableRoles(auth()->user(), $tenant()) !== [])
                    ->schema(fn () => [
                        TextInput::make('email')->email()->required()->maxLength(255),
                        Select::make('role')->required()->default('viewer')
                            ->options($team->assignableRoles(auth()->user(), $tenant()))
                            ->helperText('Owners manage everything including other owners. Admins manage domains, alerts and the team. Viewers can only look.'),
                    ])
                    ->action(fn (array $data) => self::attempt(
                        fn () => $team->invite($tenant(), $data['email'], $data['role'], auth()->user()),
                        "Invitation sent to {$data['email']}",
                    )),
            ])
            ->recordActions([
                Action::make('role')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->visible(fn (Membership $m) => $m->user_id !== auth()->id() && $team->assignableRoles(auth()->user(), $tenant()) !== [])
                    ->fillForm(fn (Membership $m) => ['role' => $m->role])
                    ->schema(fn () => [Select::make('role')->required()->options($team->assignableRoles(auth()->user(), $tenant()))])
                    ->action(fn (Membership $m, array $data) => self::attempt(fn () => $team->changeRole($m, $data['role'], auth()->user()), 'Role updated')),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Membership $m) => "{$m->user?->email} will lose access to this organisation.")
                    ->visible(fn (Membership $m) => $m->user_id !== auth()->id() && $team->assignableRoles(auth()->user(), $tenant()) !== [])
                    ->action(fn (Membership $m) => self::attempt(fn () => $team->remove($m, auth()->user()), 'Member removed')),
            ])
            ->emptyStateHeading('No members yet');
    }

    /** Pending invitations, with revoke. */
    public static function invitations(Table $table): Table
    {
        return $table
            ->heading('Pending invitations')
            ->columns([
                TextColumn::make('email'),
                TextColumn::make('role')->badge()->color('gray')->formatStateUsing(fn (string $state) => TeamService::ROLES[$state] ?? $state),
                TextColumn::make('inviter.name')->label('Invited by')->placeholder('—'),
                TextColumn::make('expires_at')->label('Expires')->since(),
            ])
            ->recordActions([
                Action::make('revoke')->label('Revoke')->color('danger')->icon(Heroicon::OutlinedXMark)->requiresConfirmation()
                    ->action(fn (Invitation $record) => self::attempt(fn () => app(TeamService::class)->revoke($record, auth()->user()), 'Invitation revoked')),
            ])
            ->paginated(false)
            ->emptyStateHeading('No pending invitations');
    }

    private static function attempt(Closure $fn, string $success): void
    {
        try {
            $fn();
            Notification::make()->title($success)->success()->send();
        } catch (TeamRuleException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
        }
    }
}
