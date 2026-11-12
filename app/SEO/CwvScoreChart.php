<?php

namespace App\SEO;

use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Server-rendered SVG line charts for the Core Web Vitals trend page:
 * one score axis with Lighthouse band underlays, the app alert
 * threshold as a dashed guide, one polyline per locale series (gaps
 * where a run is missing — never interpolated), and per-point dots
 * with native <title> tooltips.
 *
 * Fixed hex palette on purpose (not Tailwind classes): the SVG is
 * assembled in PHP, and Filament's Tailwind build only keeps classes
 * it can see in templates — a class-only-cited-in-PHP would be purged
 * to an invisible line. The chosen 500-scale tones read on both light
 * and dark panels.
 */
final class CwvScoreChart
{
    /** Locale stroke colors: EN blue, DE pink — matches the page legend. */
    private const LOCALE_COLORS = [
        'en' => '#3b82f6',
        'de' => '#ec4899',
    ];

    private const GUIDE_COLOR = '#94a3b8';

    /**
     * Render a chart. $series: locale => list<?int> aligned to the
     * run axis (null = gap). $options: width, height, axes (draw run
     * labels + band values), yMin (defaults to 40), runs (run dates
     * for axis labels, 'Y-m-d' strings or Carbonables).
     *
     * @param  array<string, list<?int>>  $series
     * @param  array{width?: int, height?: int, axes?: bool, yMin?: int, runs?: list<\DateTimeInterface|string>}  $options
     */
    public static function render(array $series, array $options = []): HtmlString
    {
        $width = $options['width'] ?? 320;
        $height = $options['height'] ?? 46;
        $axes = $options['axes'] ?? false;
        $yMin = $options['yMin'] ?? 40;
        $runs = $options['runs'] ?? [];

        $runCount = 0;

        foreach ($series as $points) {
            $runCount = max($runCount, count($points));
        }

        if ($runCount < 2) {
            $yMin = max($yMin, 0);
        }

        [$padL, $padR, $padT, $padB] = $axes ? [26, 12, 10, 18] : [4, 8, 5, 5];
        $innerW = max(1, $width - $padL - $padR);
        $innerH = max(1, $height - $padT - $padB);
        $yMax = 100;
        $threshold = CwvTrendService::threshold();

        $x = fn (int $i): float => $runCount <= 1
            ? $padL + $innerW / 2
            : $padL + ($i / ($runCount - 1)) * $innerW;
        $y = fn (int|float $score): float => $padT + (1 - (max($yMin, min($yMax, $score)) - $yMin) / ($yMax - $yMin)) * $innerH;

        $parts = [];

        // Band underlays: good (>=90), threshold..90, needs (50..threshold).
        $parts[] = sprintf('<rect x="%d" y="%.1f" width="%d" height="%.1f" fill="#10b981" opacity="0.09"/>', $padL, $y(100), $innerW, max(0, $y(CwvTrendService::SCORE_GOOD) - $y(100)));
        $parts[] = sprintf('<rect x="%d" y="%.1f" width="%d" height="%.1f" fill="#84cc16" opacity="0.09"/>', $padL, $y(CwvTrendService::SCORE_GOOD), $innerW, max(0, $y($threshold) - $y(CwvTrendService::SCORE_GOOD)));
        $parts[] = sprintf('<rect x="%d" y="%.1f" width="%d" height="%.1f" fill="#f59e0b" opacity="0.08"/>', $padL, $y($threshold), $innerW, max(0, $y(CwvTrendService::SCORE_NEEDS) - $y($threshold)));

        // Alert threshold guide.
        $parts[] = sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="%s" stroke-dasharray="4 3" stroke-width="1"/>', $padL, $width - $padR, $y($threshold), $y($threshold), self::GUIDE_COLOR);

        // One polyline per locale series; gaps break the path.
        foreach ($series as $locale => $points) {
            $color = self::LOCALE_COLORS[$locale] ?? self::GUIDE_COLOR;
            $d = '';
            $pen = false;

            foreach ($points as $i => $score) {
                if ($score === null) {
                    $pen = false;

                    continue;
                }

                $d .= ($pen ? 'L' : 'M').sprintf('%.1f %.1f ', $x($i), $y($score));
                $pen = true;
            }

            $parts[] = sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="%s" stroke-linejoin="round" stroke-linecap="round"/>', trim($d), $color, $axes ? '2.2' : '1.8');

            foreach ($points as $i => $score) {
                if ($score === null) {
                    continue;
                }

                $parts[] = sprintf('<circle cx="%.1f" cy="%.1f" r="%s" fill="%s"><title>%s · %s · %d</title></circle>', $x($i), $y($score), $axes ? '3.2' : '2.4', $color, ucfirst($locale), self::runLabel($runs, $i), $score);
            }
        }

        if ($axes) {
            foreach ([100, CwvTrendService::SCORE_GOOD, $threshold] as $score) {
                $parts[] = sprintf('<text x="%d" y="%.1f" font-size="8.5" fill="%s" text-anchor="end" dominant-baseline="middle">%d</text>', $padL - 4, $y($score), self::GUIDE_COLOR, $score);
            }

            $firstSeries = reset($series);
            $firstKeys = $firstSeries === false ? [] : array_keys($firstSeries);

            foreach ($firstKeys as $i) {
                $anchor = $i === 0 ? 'start' : ($i === $runCount - 1 ? 'end' : 'middle');
                $parts[] = sprintf('<text x="%.1f" y="%d" font-size="8.5" fill="%s" text-anchor="%s">%s</text>', $x($i), $height - 5, self::GUIDE_COLOR, $anchor, self::runLabel($runs, $i));
            }
        }

        return new HtmlString(sprintf(
            '<svg viewBox="0 0 %d %d" class="w-full" style="display:block;height:%dpx" role="img" aria-label="Core Web Vitals score trend">%s</svg>',
            $width,
            $height,
            $height,
            implode('', $parts),
        ));
    }

    /**
     * Human run label for tooltips and the x axis: the run's date as
     * "15 Oct" (EN-only admin surface — the Filament/ exemption from
     * the K002 format_date rule, same as the parent page's dates).
     *
     * @param  list<\DateTimeInterface|string>  $runs
     */
    private static function runLabel(array $runs, int $index): string
    {
        $run = $runs[$index] ?? null;

        if ($run === null) {
            return 'run '.($index + 1);
        }

        $date = $run instanceof \DateTimeInterface ? $run : Carbon::parse($run);

        return $date->format('d M');
    }
}
