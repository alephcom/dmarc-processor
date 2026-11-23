<?php

namespace App\Filament\Widgets;

use App\Services\DomainStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Sparklines and "vs prior period" descriptions for dashboard stat cards.
 */
final class StatTrends
{
    /**
     * Message volume with a daily sparkline and the change against the previous period.
     *
     * @param  array<string, array{pass: int, fail: int}>  $daily  from {@see DomainStats::daily()}
     */
    public static function volume(string $label, array $daily, int $total, int $previous): Stat
    {
        $change = DomainStats::change($total, $previous);

        return Stat::make($label, number_format($total))
            ->chart(array_map(fn (array $d) => $d['pass'] + $d['fail'], array_values($daily)))
            ->color('primary')
            ->description(match (true) {
                $total === 0 => 'No reports yet',
                $change === null => 'Nothing to compare yet',
                $change == 0 => 'Same as prior period',
                default => self::signed($change).'% vs prior period',
            })
            ->descriptionIcon(self::arrow($change));
    }

    /**
     * DMARC pass rate with a daily sparkline and the change in percentage points.
     *
     * @param  array<string, array{pass: int, fail: int}>  $daily
     * @param  array{total: int, dmarc_pass: int}  $current
     * @param  array{total: int, dmarc_pass: int}  $previous
     */
    public static function passRate(array $daily, array $current, array $previous): Stat
    {
        $pass = DomainStats::rate($current['dmarc_pass'], $current['total']);
        $before = DomainStats::rate($previous['dmarc_pass'], $previous['total']);
        $delta = $pass !== null && $before !== null ? round($pass - $before, 1) : null;

        // Days without mail are left out rather than drawn as 0%, which would look like a total failure.
        $rates = array_values(array_filter(array_map(
            fn (array $d) => DomainStats::rate($d['pass'], $d['pass'] + $d['fail']),
            $daily,
        ), fn (?float $r) => $r !== null));

        return Stat::make('Passing DMARC', $pass === null ? '—' : $pass.'%')
            ->chart(count($rates) > 1 ? $rates : [])
            ->color($pass === null ? 'gray' : ($pass >= 98 ? 'success' : ($pass >= 90 ? 'warning' : 'danger')))
            ->description(match (true) {
                $pass === null => 'Waiting for data',
                $delta === null => number_format($current['total'] - $current['dmarc_pass']).' failing',
                $delta == 0 => 'Same as prior period',
                default => self::signed($delta).' pts vs prior period',
            })
            ->descriptionIcon(self::arrow($delta));
    }

    private static function signed(float $n): string
    {
        return ($n > 0 ? '+' : '').rtrim(rtrim(number_format($n, 1), '0'), '.');
    }

    private static function arrow(?float $change): ?Heroicon
    {
        return match (true) {
            $change === null || $change == 0 => null,
            $change > 0 => Heroicon::ArrowTrendingUp,
            default => Heroicon::ArrowTrendingDown,
        };
    }
}
