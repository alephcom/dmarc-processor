<?php

namespace App\Reports;

use App\Filament\Widgets\PassFailChart;

/**
 * Server-rendered stacked bar chart for printable reports. Inline SVG prints crisply,
 * needs no JavaScript, and uses the same validated colours as the dashboard chart.
 */
final class SvgPassFailChart
{
    /** @param array<string, array{pass: int, fail: int}> $daily */
    public static function render(array $daily, int $width = 720, int $height = 230): string
    {
        $b = PassFailChart::bucket($daily);
        $n = count($b['labels']);
        $left = 48;
        $right = 8;
        $top = 28;
        $bottom = 28;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $totals = array_map(fn ($p, $f) => $p + $f, $b['pass'], $b['fail']);
        $max = self::niceMax(max([1, ...$totals]));
        $slot = $n > 0 ? $plotW / $n : $plotW;
        $barW = max(2, min(28, $slot * 0.7));
        $y = fn (float $v) => $top + $plotH - ($v / $max * $plotH);
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);

        $out = [];
        $out[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-labelledby="chart-title chart-desc" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="11">', $width, $height);
        $sumPass = array_sum($b['pass']);
        $sumAll = array_sum($totals);
        $out[] = '<title id="chart-title">Messages passing and failing DMARC per '.$b['unit'].'</title>';
        $out[] = '<desc id="chart-desc">'.number_format($sumAll).' messages in total, '.number_format($sumPass).' passing.</desc>';

        // Legend (identity never by colour alone: each swatch is labelled).
        $out[] = sprintf('<rect x="%d" y="6" width="10" height="10" rx="2" fill="%s"/><text x="%d" y="15" fill="#52514e">Passing DMARC</text>', $left, PassFailChart::PASS, $left + 14);
        $out[] = sprintf('<rect x="%d" y="6" width="10" height="10" rx="2" fill="%s"/><text x="%d" y="15" fill="#52514e">Failing DMARC</text>', $left + 112, PassFailChart::FAIL, $left + 126);

        // Gridlines and y labels.
        for ($i = 0; $i <= 4; $i++) {
            $v = $max / 4 * $i;
            $yy = round($y($v), 1);
            $out[] = sprintf('<line x1="%d" x2="%d" y1="%s" y2="%s" stroke="%s" stroke-width="1"/>', $left, $width - $right, $yy, $yy, $i === 0 ? '#c3c2b7' : '#e1e0d9');
            $out[] = sprintf('<text x="%d" y="%s" text-anchor="end" fill="#898781">%s</text>', $left - 6, $yy + 4, $e(self::short($v)));
        }

        // Bars: pass at the bottom, fail stacked on top with a rounded top edge.
        $every = max(1, (int) ceil($n / 10));
        foreach ($b['labels'] as $i => $label) {
            $x = $left + $slot * $i + ($slot - $barW) / 2;
            $p = $b['pass'][$i];
            $f = $b['fail'][$i];
            if ($p > 0) {
                $out[] = sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"><title>%s: %s passing</title></rect>', $x, $y($p), $barW, $y(0) - $y($p), PassFailChart::PASS, $e($label), number_format($p));
            }
            if ($f > 0) {
                $out[] = sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%.1f" fill="%s"><title>%s: %s failing</title></rect>', $x, $y($p + $f), $barW, $y($p) - $y($p + $f), min(3, $barW / 4), PassFailChart::FAIL, $e($label), number_format($f));
            }
            if ($i % $every === 0) {
                $out[] = sprintf('<text x="%.1f" y="%d" text-anchor="middle" fill="#898781">%s</text>', $x + $barW / 2, $height - 10, $e(str_replace('Week of ', '', $label)));
            }
        }
        $out[] = '</svg>';

        return implode("\n", $out);
    }

    private static function niceMax(int $v): int
    {
        $mag = 10 ** (strlen((string) $v) - 1);
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $mag) {
                return (int) ($m * $mag);
            }
        }

        return $v;
    }

    private static function short(float $v): string
    {
        return match (true) {
            $v >= 1_000_000 => round($v / 1_000_000, 1).'M',
            $v >= 1_000 => round($v / 1_000, 1).'k',
            default => (string) (int) $v,
        };
    }
}
