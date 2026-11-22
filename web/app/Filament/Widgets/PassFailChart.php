<?php

namespace App\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Support\RawJs;

/**
 * Stacked daily (or weekly) bars of messages passing vs failing DMARC.
 *
 * Colours: categorical slots 1 (blue) and 2 (orange) of the reference data-viz palette,
 * validated as a pair for colour-vision deficiency in light and dark mode. Deliberately
 * not green/red, which colourblind readers can't separate.
 */
final class PassFailChart
{
    public const PASS = '#2a78d6';

    public const FAIL = '#eb6834';

    /**
     * Daily points, or weekly buckets for long ranges (a year of daily bars is unreadable).
     *
     * @param  array<string, array{pass: int, fail: int}>  $daily
     * @return array{labels: list<string>, pass: list<int>, fail: list<int>, unit: string}
     */
    public static function bucket(array $daily): array
    {
        $unit = 'day';
        if (count($daily) > 120) {
            $weekly = [];
            foreach ($daily as $day => $v) {
                $week = CarbonImmutable::parse($day)->startOfWeek()->toDateString();
                $weekly[$week]['pass'] = ($weekly[$week]['pass'] ?? 0) + $v['pass'];
                $weekly[$week]['fail'] = ($weekly[$week]['fail'] ?? 0) + $v['fail'];
            }
            $daily = $weekly;
            $unit = 'week';
        }

        return [
            'labels' => array_map(fn ($d) => ($unit === 'week' ? 'Week of ' : '').CarbonImmutable::parse($d)->format('M j'), array_keys($daily)),
            'pass' => array_values(array_column($daily, 'pass')),
            'fail' => array_values(array_column($daily, 'fail')),
            'unit' => $unit,
        ];
    }

    /** @param array<string, array{pass: int, fail: int}> $daily */
    public static function data(array $daily): array
    {
        $b = self::bucket($daily);
        $labels = $b['labels'];

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Passing DMARC',
                    'data' => $b['pass'],
                    'backgroundColor' => self::PASS,
                    'borderWidth' => 0,
                    'stack' => 'mail',
                    'maxBarThickness' => 28,
                ],
                [
                    'label' => 'Failing DMARC',
                    'data' => $b['fail'],
                    'backgroundColor' => self::FAIL,
                    'borderWidth' => 0,
                    'borderRadius' => ['topLeft' => 4, 'topRight' => 4],
                    'borderSkipped' => false,
                    'stack' => 'mail',
                    'maxBarThickness' => 28,
                ],
            ],
        ];
    }

    public static function options(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', align: 'start', labels: { boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 2 } },
                    tooltip: {
                        callbacks: {
                            footer: (items) => {
                                const total = items.reduce((s, i) => s + i.parsed.y, 0);
                                const pass = items.find(i => i.datasetIndex === 0)?.parsed.y ?? 0;
                                return total ? `${total.toLocaleString()} messages · ${(pass / total * 100).toFixed(1)}% passing` : 'No reports';
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                    y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(137,135,129,0.18)' }, border: { display: false }, ticks: { precision: 0 } },
                },
            }
        JS);
    }
}
