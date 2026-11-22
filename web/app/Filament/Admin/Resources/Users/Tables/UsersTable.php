<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Models\User;
use App\Team\Impersonation;
use App\Team\TeamRuleException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tenants'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('tenants.name')->label('Customers')->badge()->color('gray')->limitList(3)->placeholder('—'),
                IconColumn::make('is_super_admin')->label('Super-admin')->boolean(),
                IconColumn::make('mfa')->label('2FA')->boolean()->state(fn (User $u) => (bool) $u->app_authentication_secret),
                TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([TernaryFilter::make('is_super_admin')->label('Super-admins')])
            ->defaultSort('name')
            ->recordActions([
                self::impersonateAction(),
                ActionGroup::make([
                    EditAction::make(),
                    self::resetMfaAction(),
                    DeleteAction::make()->visible(fn (User $u) => $u->id !== auth()->id()),
                ]),
            ]);
    }

    public static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label('Sign in as')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('gray')
            ->visible(fn (User $u) => Impersonation::canImpersonate(auth()->user(), $u) && $u->tenants->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading(fn (User $u) => "Sign in as {$u->email}?")
            ->modalDescription('You will see exactly what they see. Everything you do is recorded under both your names. Use "Return to MSP admin" in the banner to switch back.')
            ->action(function (User $u) {
                try {
                    app(Impersonation::class)->start(auth()->user(), $u);
                } catch (TeamRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return null;
                }

                return redirect('/app');
            });
    }

    public static function resetMfaAction(): Action
    {
        return Action::make('reset_mfa')
            ->label('Reset two-factor')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (User $u) => (bool) $u->app_authentication_secret && $u->id !== auth()->id())
            ->requiresConfirmation()
            ->modalDescription('Use this when someone has lost their authenticator. They will set up two-factor again at next sign-in (if required).')
            ->action(function (User $u) {
                $u->saveAppAuthenticationSecret(null);
                $u->saveAppAuthenticationRecoveryCodes(null);
                Notification::make()->title('Two-factor reset')->success()->send();
            });
    }
}
