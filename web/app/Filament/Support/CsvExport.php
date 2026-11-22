<?php

namespace App\Filament\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Export CSV" table header action: the rows the table currently shows (search, filters and sort
 * applied, all pages) with its visible columns. Synchronous, which is fine at our scale.
 */
final class CsvExport
{
    /** Hard cap so a huge table can't tie up a web worker. */
    public const MAX_ROWS = 20000;

    /** @param  \Closure(): string  $filename  without extension */
    public static function action(\Closure $filename): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (HasTable $livewire) => self::download($livewire, $filename()));
    }

    public static function download(HasTable $livewire, string $filename): StreamedResponse
    {
        $table = $livewire->getTable();
        $columns = array_values(array_filter($table->getVisibleColumns(), fn ($c) => ! $c->isToggledHidden()));
        $records = $livewire->getFilteredSortedTableQuery()->limit(self::MAX_ROWS)->get();

        return response()->streamDownload(function () use ($columns, $records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => self::cell((string) $c->getLabel()), $columns), escape: '');
            foreach ($records as $record) {
                fputcsv($out, array_map(fn ($c) => self::cell($c->record($record)->getState()), $columns), escape: '');
            }
            fclose($out);
        }, Str::slug($filename).'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Plain text for a cell. Values that a spreadsheet would run as a formula are prefixed with a quote:
     * sender names, reverse DNS and report contents come from outside and must not execute.
     */
    public static function cell(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof BackedEnum => (string) $value->value,
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)),
            default => (string) $value,
        };

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
