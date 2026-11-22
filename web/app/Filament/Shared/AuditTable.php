<?php

namespace App\Filament\Shared;

use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AuditTable
{
    public static function configure(Table $table, bool $msp): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['actor', 'impersonator', 'tenant']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor.email')->label('Who')->placeholder('System')
                    ->description(fn (AuditLog $r) => $r->impersonator ? 'via '.$r->impersonator->email.' (support)' : null),
                TextColumn::make('description')->label('What')->wrap()->searchable(),
                TextColumn::make('tenant.name')->label('Customer')->placeholder('—')->visible($msp),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')->label('Type')
                    ->options(['auth' => 'Sign-ins', 'membership' => 'Team', 'invitation' => 'Invitations', 'domain' => 'Domains', 'notification_channel' => 'Notifications', 'alert' => 'Alerts', 'impersonation' => 'Support sign-ins'])
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where('action', 'like', $data['value'].'%') : $query),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('details')->iconButton()->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (AuditLog $r) => $r->description)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema(fn (AuditLog $r) => [
                        TextEntry::make('a')->label('Action')->state($r->action)->fontFamily('mono'),
                        TextEntry::make('u')->label('User agent')->state($r->user_agent)->placeholder('—'),
                        KeyValueEntry::make('c')->label('Changes')->state(collect($r->changes ?? [])->map(
                            fn ($v) => is_array($v) && count($v) === 2 ? json_encode($v[0]).' → '.json_encode($v[1]) : json_encode($v)
                        )->all())->visible((bool) $r->changes),
                    ]),
            ])
            ->emptyStateHeading('No activity yet');
    }
}
