<?php

namespace App\Filament\Widgets;

use App\Filament\App\Resources\Domains\DomainResource;
use App\Models\DailyDomainStat;
use App\Models\Domain;
use App\Services\DomainStats;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every domain with its 30-day health. Subclasses decide which domains (one tenant vs all).
 */
abstract class DomainHealthTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    abstract protected function scope(Builder $query): Builder;

    protected function showTenant(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $from = DomainStats::from(30);
        $sum = fn (string $col) => DailyDomainStat::query()->selectRaw("COALESCE(SUM({$col}), 0)")
            ->whereColumn('daily_domain_stats.domain_id', 'domains.id')->where('day', '>=', $from);

        return $table
            ->heading('Domains')
            ->description('Last 30 days')
            ->query(fn (): Builder => $this->scope(Domain::query()->with('tenant'))
                ->select('domains.*')
                ->addSelect(['messages' => $sum('total'), 'passing' => $sum('dmarc_pass')])
                ->withCount(['alerts as open_alerts' => fn (Builder $q) => $q->whereNull('resolved_at')]))
            ->defaultSort('messages', 'desc')
            ->columns([
                TextColumn::make('name')->label('Domain')->weight('medium')->searchable()->sortable(),
                TextColumn::make('tenant.name')->label('Customer')->visible($this->showTenant())->sortable(),
                TextColumn::make('dns_verified_at')->label('DMARC record')->badge()
                    ->state(fn (Domain $d) => $d->dns_verified_at ? 'Live' : ($d->dns_checked_at ? 'Not live' : 'Not checked'))
                    ->color(fn (Domain $d) => $d->dns_verified_at ? 'success' : 'warning')
                    ->icon(fn (Domain $d) => $d->dns_verified_at ? Heroicon::CheckCircle : Heroicon::ExclamationTriangle),
                TextColumn::make('current_policy')->label('Policy')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, Domain $d) => $state ? "p={$state}".($d->current_pct && $d->current_pct < 100 ? " ({$d->current_pct}%)" : '') : null)
                    ->description(fn (Domain $d) => $d->current_policy === $d->target_policy && ($d->current_pct ?? 100) >= 100
                        ? 'At target' : "Target p={$d->target_policy}"),
                TextColumn::make('messages')->numeric()->alignEnd()->sortable(),
                TextColumn::make('pass_rate')->label('DMARC pass')->alignEnd()->badge()
                    ->state(fn (Domain $d) => DomainStats::rate((int) $d->passing, (int) $d->messages))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 98 ? 'success' : ($state >= 90 ? 'warning' : 'danger'))),
                TextColumn::make('open_alerts')->label('Open alerts')->alignEnd()->sortable()->badge()
                    ->formatStateUsing(fn (int $state) => $state ?: '—')
                    ->color(fn (int $state) => $state ? 'danger' : 'gray'),
                TextColumn::make('last_report_at')->label('Last report')->since()->placeholder('Never')->sortable()
                    ->color(fn (Domain $d) => $d->status === Domain::STATUS_ACTIVE && $d->last_report_at?->lt(now()->subHours(72)) ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Domain::STATUS_PENDING => 'Waiting for reports',
                    Domain::STATUS_ACTIVE => 'Active',
                    Domain::STATUS_PAUSED => 'Paused',
                ]),
            ])
            ->recordUrl(fn (Domain $d) => DomainResource::getUrl('view', ['record' => $d], panel: 'app', tenant: $d->tenant))
            ->emptyStateHeading('No domains yet')
            ->paginated([10, 25, 50]);
    }
}
