<?php

namespace App\Filament\App\Resources\Domains\Widgets;

use App\Models\Domain;
use App\Models\Report;
use App\Services\DomainStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class DomainReportersTable extends TableWidget
{
    public ?Domain $record = null;

    public int $days = 30;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Who sent us reports')
            ->description('Mailbox providers that reported on mail they received from your domain.')
            ->query(fn (): Builder => Report::query()
                ->where('domain_id', $this->record->id)
                ->where('date_begin', '>=', DomainStats::from($this->days))
                ->groupBy('reporter_org')
                ->selectRaw('MIN(id) AS id, reporter_org, COUNT(*) AS reports, SUM(message_count) AS messages, MAX(date_end) AS latest'))
            ->defaultSort('messages', 'desc')
            ->columns([
                TextColumn::make('reporter_org')->label('Reporter'),
                TextColumn::make('reports')->numeric()->alignEnd()->sortable(),
                TextColumn::make('messages')->numeric()->alignEnd()->sortable(),
                TextColumn::make('latest')->label('Latest report')->since()->sortable(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No reports in this period');
    }
}
