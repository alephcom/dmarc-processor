<?php

namespace App\Filament\Shared;

use App\Models\Tenant;
use App\Reports\ShareLinks;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** "Report" menu: open the printable report, create a share link, revoke share links. */
final class ReportActions
{
    /**
     * @param  Closure(): string  $url  printable report URL for the signed-in user
     * @param  Closure(): string  $shareUrl  creates a signed share link
     */
    public static function group(Closure $url, Closure $shareUrl): ActionGroup
    {
        $canManage = fn () => ($t = Filament::getTenant()) instanceof Tenant && auth()->user()->canManage($t);

        return ActionGroup::make([
            Action::make('printable')->label('Printable report')->icon(Heroicon::OutlinedPrinter)
                ->url($url, shouldOpenInNewTab: true),
            Action::make('share')->label('Share link')->icon(Heroicon::OutlinedLink)
                ->visible($canManage)
                ->modalHeading('Share this report')
                ->modalDescription('Anyone with this link can view the report, without signing in, for '.ShareLinks::TTL_DAYS.' days.')
                ->fillForm(fn () => ['link' => $shareUrl()])
                ->schema([TextInput::make('link')->label('Link')->readOnly()->copyable()])
                ->modalSubmitAction(false)->modalCancelActionLabel('Done'),
            Action::make('revoke_shares')->label('Revoke all share links')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible($canManage)
                ->requiresConfirmation()
                ->modalDescription('Every report link shared for this organisation stops working. You can create new ones afterwards.')
                ->action(function () {
                    ShareLinks::revokeAll(Filament::getTenant());
                    Notification::make()->title('Share links revoked')->success()->send();
                }),
        ])->label('Report')->icon(Heroicon::OutlinedDocumentChartBar)->button()->color('gray');
    }
}
