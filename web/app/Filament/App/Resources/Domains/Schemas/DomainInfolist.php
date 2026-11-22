<?php

namespace App\Filament\App\Resources\Domains\Schemas;

use App\Alerts\Severity;
use App\Filament\App\Resources\Alerts\AlertResource;
use App\Models\Alert;
use App\Models\Domain;
use App\Services\PolicyAdvice;
use App\Services\PolicyAdvisor;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DomainInfolist
{
    /** @return list<mixed> */
    public static function policyGuide(PolicyAdvice $advice): array
    {
        $callout = Callout::make($advice->headline)->description($advice->explanation);
        $items = [match ($advice->status) {
            'ready' => $callout->success(),
            'done' => $callout->success(),
            'blocked' => $callout->warning(),
            default => $callout->info(),
        }];
        foreach ($advice->checks as [$ok, $text]) {
            // A symbol as well as colour, so pass/fail doesn't rely on colour vision.
            $items[] = Text::make(($ok ? '✓ ' : '✗ ').$text)->color($ok ? 'success' : 'danger');
        }
        if ($advice->record) {
            $items[] = TextEntry::make('next_record')->label('Publish this TXT record at _dmarc')
                ->state($advice->record)->fontFamily('mono')->copyable()
                ->helperText('Replace your existing DMARC record. We\'ll pick up the change within a day and the guide moves on to the next step.');
        }

        return $items;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Open alerts')
                ->icon(Heroicon::OutlinedBellAlert)
                ->visible(fn (Domain $record) => $record->alerts()->open()->exists())
                ->schema(fn (Domain $record) => $record->alerts()->open()->latest('opened_at')->limit(5)->get()
                    ->map(fn (Alert $a) => Callout::make($a->title)
                        ->description(str($a->body)->limit(220)->toString())
                        ->color(Severity::color($a->severity))
                        ->icon($a->severity === Severity::CRITICAL ? Heroicon::OutlinedExclamationCircle : Heroicon::OutlinedExclamationTriangle)
                        ->actions([Action::make('view'.$a->id)->label('View alert')->link()->url(AlertResource::getUrl('view', ['record' => $a]))]))
                    ->all())
                ->columnSpanFull(),
            Section::make('Policy guide')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->description(fn (Domain $record) => 'Current policy: '.app(PolicyAdvisor::class)->advise($record)->current.' · target: p='.$record->target_policy)
                ->schema(fn (Domain $record) => self::policyGuide(app(PolicyAdvisor::class)->advise($record)))
                ->columnSpanFull(),
            Section::make('Reporting & DNS')
                ->description('Mailbox providers send daily DMARC reports to this address.')
                ->collapsible()
                ->schema([
                    TextEntry::make('rua_address')
                        ->label('Reporting address')
                        ->state(fn (Domain $record) => $record->ruaAddress())
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('dns_verified_at')
                        ->label('DMARC record')
                        ->state(fn (Domain $record) => $record->dns_verified_at ? 'Live since '.$record->dns_verified_at->toFormattedDateString() : 'Not verified yet')
                        ->badge()
                        ->color(fn (Domain $record) => $record->dns_verified_at ? 'success' : 'warning')
                        ->helperText(fn (Domain $record) => $record->dns_checked_at ? 'Last checked '.$record->dns_checked_at->diffForHumans() : 'Not checked yet'),
                ])->columns(1),
            Section::make('Status')->collapsible()->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('current_policy')->label('Published policy')->placeholder('Not checked yet'),
                TextEntry::make('target_policy')->label('Target policy'),
                TextEntry::make('first_report_at')->label('First report')->dateTime()->placeholder('Waiting…'),
                TextEntry::make('last_report_at')->label('Last report')->since()->placeholder('Waiting…'),
            ])->columns(3),
        ]);
    }
}
