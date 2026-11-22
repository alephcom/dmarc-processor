<?php

namespace App\Filament\App\Resources\Domains\Widgets;

use App\Filament\Support\CsvExport;
use App\Models\DailySourceStat;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Services\DomainStats;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class DomainSourcesTable extends TableWidget
{
    public ?Domain $record = null;

    public int $days = 30;

    protected int|string|array $columnSpan = 'full';

    public const CLASSIFICATIONS = ['legit' => 'Legitimate', 'unknown' => 'Unknown', 'suspicious' => 'Suspicious'];

    public function table(Table $table): Table
    {
        $from = DomainStats::from($this->days);
        $canManage = auth()->user()?->canManage($this->record->tenant) ?? false;
        $sum = fn (string $col) => DailySourceStat::query()
            ->selectRaw("COALESCE(SUM({$col}), 0)")
            ->whereColumn('daily_source_stats.domain_id', 'domain_sources.domain_id')
            ->whereColumn('daily_source_stats.source_key', 'domain_sources.source_key')
            ->where('day', '>=', $from);

        return $table
            ->heading('Who is sending as '.$this->record->name)
            ->description('Grouped by service where we can identify it, otherwise by network. Mark the ones you use as legitimate.'
                .(config('dmarc.ipinfo_token') ? '' : ' Network data: IP to ASN Lite by DB-IP (db-ip.com), CC BY 4.0.'))
            ->query(fn (): Builder => DomainSource::query()
                ->where('domain_id', $this->record->id)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('daily_source_stats')
                    ->whereColumn('daily_source_stats.domain_id', 'domain_sources.domain_id')
                    ->whereColumn('daily_source_stats.source_key', 'domain_sources.source_key')
                    ->where('day', '>=', $from))
                ->select('domain_sources.*')
                ->addSelect([
                    'total' => $sum('total'),
                    'pass' => $sum('dmarc_pass'),
                    'spf' => $sum('spf_aligned'),
                    'dkim' => $sum('dkim_aligned'),
                    'ip_count' => DailySourceStat::query()->selectRaw('COUNT(DISTINCT source_ip)')
                        ->whereColumn('daily_source_stats.domain_id', 'domain_sources.domain_id')
                        ->whereColumn('daily_source_stats.source_key', 'domain_sources.source_key')
                        ->where('day', '>=', $from),
                ]))
            ->defaultSort('total', 'desc')
            ->columns([
                TextColumn::make('label')->label('Sender')->weight('medium')->searchable()
                    ->description(fn (DomainSource $r) => match (strtok($r->source_key, ':')) {
                        'sender' => 'Identified service',
                        'asn' => 'Network',
                        default => 'Single server',
                    }),
                TextColumn::make('total')->label('Messages')->numeric()->sortable()->alignEnd(),
                TextColumn::make('pass_rate')->label('DMARC pass')->alignEnd()->badge()
                    ->state(fn (DomainSource $r) => DomainStats::rate((int) $r->pass, (int) $r->total))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 98 ? 'success' : ($state >= 90 ? 'warning' : 'danger')))
                    ->icon(fn ($state) => $state !== null && $state >= 98 ? Heroicon::CheckCircle : Heroicon::ExclamationTriangle),
                TextColumn::make('dkim_rate')->label('DKIM aligned')->alignEnd()->toggleable()
                    ->state(fn (DomainSource $r) => DomainStats::rate((int) $r->dkim, (int) $r->total))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%'),
                TextColumn::make('spf_rate')->label('SPF aligned')->alignEnd()->toggleable()
                    ->state(fn (DomainSource $r) => DomainStats::rate((int) $r->spf, (int) $r->total))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%'),
                TextColumn::make('ip_count')->label('Servers')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                $canManage
                    ? SelectColumn::make('classification')->label('Status')->options(self::CLASSIFICATIONS)->selectablePlaceholder(false)
                    : TextColumn::make('classification')->label('Status')->badge()
                        ->formatStateUsing(fn (string $state) => self::CLASSIFICATIONS[$state] ?? $state)
                        ->color(fn (string $state) => ['legit' => 'success', 'suspicious' => 'danger'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('classification')->label('Status')->options(self::CLASSIFICATIONS),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->iconButton()
                    ->tooltip('Details and how to fix')
                    ->icon(Heroicon::OutlinedChevronRight)
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalHeading(fn (DomainSource $r) => $r->label)
                    ->modalDescription(DomainStats::PERIODS[$this->days])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (DomainSource $r) => $this->detailSchema($r)),
            ])
            ->headerActions([CsvExport::action(fn () => $this->record->name.'-senders-'.$this->days.'d')])
            // Clicking anywhere on a row opens its details.
            ->recordAction('details')
            ->emptyStateHeading('No senders in this period')
            ->emptyStateDescription('Senders appear here once mailbox providers send reports for this domain.')
            ->paginated([10, 25, 50]);
    }

    /** @return list<mixed> */
    private function detailSchema(DomainSource $source): array
    {
        $d = app(DomainStats::class)->sourceDetail($source, $this->days);
        $items = [];
        foreach ($d['advice'] as $a) {
            $c = Callout::make($a['text']);
            $items[] = match ($a['level']) {
                'success' => $c->success(),
                'danger' => $c->danger(),
                'warning' => $c->warning(),
                default => $c->info(),
            };
        }
        $items[] = Grid::make(3)->schema([
            TextEntry::make('d_total')->label('Messages')->state(number_format($d['total'])),
            TextEntry::make('d_dkim')->label('DKIM aligned')->state($d['dkim_aligned_rate'] === null ? '—' : $d['dkim_aligned_rate'].'%'),
            TextEntry::make('d_spf')->label('SPF aligned')->state($d['spf_aligned_rate'] === null ? '—' : $d['spf_aligned_rate'].'%'),
        ]);
        $items[] = Section::make('Sending servers')->compact()->schema([
            RepeatableEntry::make('d_ips')->hiddenLabel()->state($d['ips'])->table([
                RepeatableEntry\TableColumn::make('IP address'),
                RepeatableEntry\TableColumn::make('Reverse DNS'),
                RepeatableEntry\TableColumn::make('Network'),
                RepeatableEntry\TableColumn::make('Messages')->alignment('end'),
                RepeatableEntry\TableColumn::make('Pass')->alignment('end'),
            ])->schema([
                TextEntry::make('ip')->fontFamily(FontFamily::Mono),
                TextEntry::make('rdns')->placeholder('—'),
                TextEntry::make('network')->placeholder('—'),
                TextEntry::make('total')->numeric(),
                TextEntry::make('pass_rate')->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%'),
            ]),
        ]);
        $items[] = Section::make('DKIM signatures seen')->compact()->schema($d['dkim'] === []
            ? [Text::make('No DKIM signatures on this mail.')->color('gray')]
            : array_map(fn ($x) => Text::make("d={$x['domain']}".($x['selector'] ? " s={$x['selector']}" : '')." — {$x['result']} (".number_format($x['count']).')'), $d['dkim']));
        $items[] = Section::make('SPF checks seen')->compact()->schema($d['spf'] === []
            ? [Text::make('No SPF results reported.')->color('gray')]
            : array_map(fn ($x) => Text::make("{$x['domain']} — {$x['result']} (".number_format($x['count']).')'), $d['spf']));

        return $items;
    }
}
