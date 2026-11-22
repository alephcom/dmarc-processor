<?php

namespace App\Filament\App\Resources\Domains\Pages;

use App\Dmarc\RawReportLocator;
use App\Filament\App\Resources\Domains\DomainResource;
use App\Filament\Support\CsvExport;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every aggregate report received for a domain: what each mailbox provider actually sent,
 * with the individual records and the original XML.
 */
class DomainReports extends ManageRelatedRecords
{
    protected static string $resource = DomainResource::class;

    protected static string $relationship = 'reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $breadcrumb = 'Reports';

    public static function canAccess(array $parameters = []): bool
    {
        // Same rule as viewing the domain; the record itself is resolved through the tenant-scoped resource query.
        return isset($parameters['record']) && DomainResource::canView($parameters['record']);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Reports for '.$this->getRecord()->name;
    }

    public function table(Table $table): Table
    {
        $passing = DB::table('report_records')->selectRaw('COALESCE(SUM(count), 0)')
            ->whereColumn('report_records.report_id', 'reports.id')
            ->where(fn ($q) => $q->where('dkim_aligned', true)->orWhere('spf_aligned', true));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('reports.*')->addSelect(['passing' => $passing]))
            ->defaultSort('date_begin', 'desc')
            ->columns([
                TextColumn::make('reporter_org')->label('Reporter')->weight('medium')->searchable()->sortable(),
                TextColumn::make('date_begin')->label('Period')->sortable()
                    ->formatStateUsing(fn (Report $r) => $r->date_begin->format('M j, H:i').' – '.$r->date_end->format('M j, H:i').' UTC'),
                TextColumn::make('message_count')->label('Messages')->numeric()->alignEnd()->sortable(),
                TextColumn::make('pass_rate')->label('DMARC pass')->alignEnd()->badge()
                    ->state(fn (Report $r) => $r->message_count ? round($r->passing / $r->message_count * 100, 1) : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 98 ? 'success' : ($state >= 90 ? 'warning' : 'danger'))),
                TextColumn::make('report_id')->label('Report ID')->limit(24)->copyable()->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Received')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('reporter_org')->label('Reporter')
                    ->options(fn () => $this->getRecord()->reports()->distinct()->orderBy('reporter_org')->pluck('reporter_org', 'reporter_org')->all()),
            ])
            ->recordActions([
                Action::make('records')
                    ->label('Records')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->modalHeading(fn (Report $r) => "{$r->reporter_org} · ".$r->date_begin->format('M j, Y'))
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Report $r) => view('filament.app.domain-reports.records', [
                        'report' => $r,
                        'records' => $r->records()->orderByDesc('count')->limit(500)->get(),
                        'total' => $r->records()->count(),
                    ])),
                Action::make('xml')
                    ->label('XML')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (Report $r) => $r->inbound_message_id !== null)
                    ->action(function (Report $r): ?StreamedResponse {
                        $xml = app(RawReportLocator::class)->xml($r);
                        if ($xml === null) {
                            Notification::make()->title('The original report is no longer available')->warning()->send();

                            return null;
                        }
                        $name = Str::slug($r->reporter_org).'-'.$r->date_begin->format('Ymd').'-'.Str::slug(Str::limit($r->report_id, 40, '')).'.xml';

                        return response()->streamDownload(fn () => print ($xml), $name, ['Content-Type' => 'application/xml']);
                    }),
            ])
            ->headerActions([CsvExport::action(fn () => $this->getRecord()->name.'-reports')])
            ->emptyStateHeading('No reports yet')
            ->emptyStateDescription('Reports appear here as mailbox providers send them, usually once a day.')
            ->paginated([25, 50, 100]);
    }
}
