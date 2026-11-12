<?php

namespace App\SEO;

use App\Models\CwvMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Trend shaping for the Core Web Vitals admin page (2026-11-12): turns
 * the append-only cwv_measurements history into the view's structured
 * payload — fleet KPIs, the per-check heat grid, per-template desktop/
 * mobile lanes with sparkline series, and the drill-down detail for one
 * selected template.
 *
 * Rows carry no locale column; it is derived from the checked URL
 * ('/de/' → de, else en) exactly like CwvCheckCommand's URL build. The
 * lab/field distinction is preserved: only performance_score has data
 * until CrUX field percentiles appear (field_data_state), so the
 * payload exposes fieldState per template and the view renders the
 * LCP/INP/CLS tiles as an honest awaiting state rather than dashes.
 *
 * @phpstan-type Check array{template: string, locale: string, strategy: string, score: int, date: string, prev: ?int}
 * @phpstan-type Lane array{score: int, en: int, de: int, prev: ?int}
 * @phpstan-type Template array{label: string, path: string, lanes: array<string, (array{series: array<string, list<?int>>, latest: ?Lane})>, worst: ?Check, band: array{key: string, label: string, bg: string, text: string}}
 * @phpstan-type Kpis array{passing: int, total: int, prevPassing: int, desktopAvg: int, prevDesktopAvg: ?int, mobileAvg: int, prevMobileAvg: ?int, worst: Check|null, window: string}
 * @phpstan-type Trend array{hasData: bool, runs: list<string>, kpis: Kpis, checks: list<Check>, templates: array<string, Template>}
 * @phpstan-type Detail array{label: string, path: string, runs: list<string>, locales: list<string>, strategies: array<string, (array{series: array<string, list<?int>>, latest: int, band: array{key: string, label: string, bg: string, text: string}})>, table: list<(array{date: string, latest: bool, cells: list<?int>})>, fieldState: string, field: array<string, ?array{lcp_ms: int, inp_ms: int, cls: float}>}
 */
final class CwvTrendService
{
    /** Canonical Lighthouse "good" boundary (config only carries the alert threshold). */
    public const SCORE_GOOD = 90;

    /** Canonical Lighthouse "needs work" lower boundary. */
    public const SCORE_NEEDS = 50;

    /**
     * Display-label overrides for the shipped template keys; anything
     * added to config('cwv.templates') later falls back to
     * Str::headline(key) — new templates render without code changes.
     *
     * @var array<string, string>
     */
    private const TEMPLATE_LABELS = [
        'home' => 'Home',
        'discovery-board-games' => 'Discovery — Board games',
        'discovery-adventures' => 'Discovery — Adventures',
        'city-hub' => 'City hub',
        'game-system' => 'Game system',
        'static-pricing' => 'Pricing',
    ];

    /**
     * Canonical Lighthouse score bands: good/watch/needs/poor. "watch"
     * is the band between the app's alert threshold (config
     * cwv.thresholds.performance_score) and 90 — passing, but visible.
     *
     * @return array{key: string, label: string, bg: string, text: string}
     */
    public static function band(int $score): array
    {
        $threshold = self::threshold();

        return match (true) {
            $score >= self::SCORE_GOOD => ['key' => 'good', 'label' => 'good', 'bg' => 'bg-emerald-100 dark:bg-emerald-950', 'text' => 'text-emerald-700 dark:text-emerald-400'],
            $score >= $threshold => ['key' => 'watch', 'label' => 'watch', 'bg' => 'bg-lime-100 dark:bg-lime-950', 'text' => 'text-lime-700 dark:text-lime-400'],
            $score >= self::SCORE_NEEDS => ['key' => 'needs', 'label' => 'needs work', 'bg' => 'bg-amber-100 dark:bg-amber-950', 'text' => 'text-amber-700 dark:text-amber-400'],
            default => ['key' => 'poor', 'label' => 'poor', 'bg' => 'bg-red-100 dark:bg-red-950', 'text' => 'text-red-700 dark:text-red-400'],
        };
    }

    /** The alert threshold, narrowed from config (int as committed; env-typed strings resolve too). */
    public static function threshold(): int
    {
        $value = config('cwv.thresholds.performance_score');

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 80);
    }

    /**
     * Google's canonical field-metric bands (good / needs improvement /
     * poor) for one LCP-ms, INP-ms, or CLS value — the same palette as
     * the score bands so the field tiles read consistently.
     *
     * @param  'lcp_ms'|'inp_ms'|'cls'  $metric
     * @return array{key: string, label: string, bg: string, text: string}
     */
    public static function fieldBand(string $metric, int|float $value): array
    {
        [$good, $needs] = match ($metric) {
            'lcp_ms' => [2_500, 4_000],
            'inp_ms' => [200, 500],
            'cls' => [0.1, 0.25],
        };

        return match (true) {
            $value <= $good => ['key' => 'good', 'label' => 'good', 'bg' => 'bg-emerald-100 dark:bg-emerald-950', 'text' => 'text-emerald-700 dark:text-emerald-400'],
            $value <= $needs => ['key' => 'needs', 'label' => 'needs improvement', 'bg' => 'bg-amber-100 dark:bg-amber-950', 'text' => 'text-amber-700 dark:text-amber-400'],
            default => ['key' => 'poor', 'label' => 'poor', 'bg' => 'bg-red-100 dark:bg-red-950', 'text' => 'text-red-700 dark:text-red-400'],
        };
    }

    /**
     * The whole-page payload: runs, KPIs, flat latest checks (heat
     * grid), and per-template lanes with per-locale series aligned to
     * the run axis (null = run missed, rendered as a gap).
     *
     * @return Trend
     */
    public function trend(): array
    {
        /** @var list<array{template: string, locale: string, strategy: string, score: int|null, date: string, field_state: string}> $rows */
        $rows = CwvMeasurement::query()
            ->orderBy('fetched_at')
            ->get()
            ->map(fn (CwvMeasurement $row): array => [
                'template' => $row->template,
                'locale' => $this->localeOf($row->url),
                'strategy' => $row->strategy,
                'score' => $row->performance_score,
                'date' => $row->fetched_at->format('Y-m-d'),
                'field_state' => $row->field_data_state,
            ])
            ->all();

        /** @var list<string> $runs */
        $runs = collect($rows)
            ->map(fn (array $row): string => $row['date'])
            ->unique()
            ->values()
            ->all();

        [$checks, $templates] = $this->buildChecksAndTemplates($rows, $runs);

        return [
            'hasData' => $runs !== [],
            'runs' => $runs,
            'kpis' => $this->kpis($checks),
            'checks' => $checks,
            'templates' => $templates,
        ];
    }

    /**
     * The compact one-line summary Search Performance embeds as a link
     * card to the full trend page.
     *
     * @return array{passing: int, total: int, desktopAvg: int, mobileAvg: int, window: string}|null
     */
    public function summaryLine(): ?array
    {
        $trend = $this->trend();

        if (! $trend['hasData']) {
            return null;
        }

        return [
            'passing' => $trend['kpis']['passing'],
            'total' => $trend['kpis']['total'],
            'desktopAvg' => $trend['kpis']['desktopAvg'],
            'mobileAvg' => $trend['kpis']['mobileAvg'],
            'window' => $trend['kpis']['window'],
        ];
    }

    /**
     * Drill-down payload for one template: per-strategy chart series
     * for the chosen locale filter, the run-by-run table, and the
     * field-data state powering the LCP/INP/CLS awaiting tiles.
     *
     * Built from the template's FULL history (not the collapsed
     * latest-per-key checks the fleet view uses) — every run needs a
     * point, gaps stay gaps.
     *
     * @param  'all'|'en'|'de'  $locale
     * @return Detail|null
     */
    public function detail(string $templateKey, string $locale): ?array
    {
        $trend = $this->trend();

        if (! $trend['hasData'] || ! isset($trend['templates'][$templateKey])) {
            return null;
        }

        $locales = $locale === 'all' ? self::configList('cwv.locales', ['en', 'de']) : [$locale];
        $runs = $trend['runs'];

        // strategy|locale|date => score, over the template's whole history.
        /** @var array<string, int> $history */
        $history = [];

        CwvMeasurement::query()
            ->where('template', $templateKey)
            ->orderBy('fetched_at')
            ->get()
            ->each(function (CwvMeasurement $row) use (&$history): void {
                if ($row->performance_score !== null) {
                    $history[$row->strategy.'|'.$this->localeOf($row->url).'|'.$row->fetched_at->format('Y-m-d')] = $row->performance_score;
                }
            });

        $scoreAt = fn (string $strategy, string $loc, string $date): ?int => $history[$strategy.'|'.$loc.'|'.$date] ?? null;

        // The dated scores for one (strategy, locale), keyed by date, oldest first.
        $datedScores = function (string $strategy, string $loc) use ($history): array {
            /** @var array<string, int> $dated */
            $dated = [];

            foreach ($history as $key => $score) {
                if (str_starts_with($key, $strategy.'|'.$loc.'|')) {
                    $dated[substr($key, strlen($strategy.'|'.$loc.'|'))] = $score;
                }
            }

            ksort($dated);

            return $dated;
        };

        $strategies = [];

        $configStrategies = self::configList('cwv.strategies', ['mobile', 'desktop']);

        foreach ($configStrategies as $strategy) {
            /** @var array<string, list<?int>> $series */
            $series = [];

            foreach ($locales as $loc) {
                $series[$loc] = array_map(
                    fn (string $date): ?int => $scoreAt($strategy, $loc, $date),
                    $runs,
                );
            }

            /** @var array<string, int> $latestPerLocale */
            $latestPerLocale = [];

            foreach ($locales as $loc) {
                $dated = $datedScores($strategy, $loc);
                $last = end($dated);
                $latestPerLocale[$loc] = $last === false ? 0 : $last;
            }

            $latest = $latestPerLocale === [] ? 0 : min($latestPerLocale);

            $strategies[$strategy] = [
                'series' => $series,
                'latest' => $latest,
                'band' => self::band($latest),
            ];
        }

        /** @var list<(array{date: string, latest: bool, cells: list<?int>})> $table */
        $table = [];

        foreach (array_reverse($runs) as $i => $date) {
            /** @var list<?int> $cells */
            $cells = [];

            foreach ($configStrategies as $strategy) {
                foreach ($locales as $loc) {
                    $cells[] = $scoreAt($strategy, $loc, $date);
                }
            }

            $table[] = [
                'date' => $date,
                'latest' => $i === 0,
                'cells' => $cells,
            ];
        }

        $fieldState = CwvMeasurement::query()
            ->where('template', $templateKey)
            ->latest('fetched_at')
            ->value('field_data_state');

        // Latest field metrics per strategy — the newest row per
        // (strategy, locale) that actually carries them, worst locale
        // per strategy (consistent with the lab lanes).
        /** @var array<string, ?array{lcp_ms: int, inp_ms: int, cls: float}> $field */
        $field = [];

        foreach (self::configList('cwv.strategies', ['mobile', 'desktop']) as $strategy) {
            $perLocale = [];

            foreach (self::configList('cwv.locales', ['en', 'de']) as $loc) {
                /** @var ?CwvMeasurement $row */
                $row = CwvMeasurement::query()
                    ->where('template', $templateKey)
                    ->where('strategy', $strategy)
                    ->whereNotNull('lcp_ms')
                    ->where('url', 'like', '%/'.$loc.'/%')
                    ->latest('fetched_at')
                    ->first();

                if ($row !== null) {
                    $perLocale[$loc] = ['lcp_ms' => $row->lcp_ms ?? 0, 'inp_ms' => $row->inp_ms ?? 0, 'cls' => $row->cls ?? 0.0];
                }
            }

            $field[$strategy] = $perLocale === [] ? null : [
                'lcp_ms' => max(array_column($perLocale, 'lcp_ms')),
                'inp_ms' => max(array_column($perLocale, 'inp_ms')),
                'cls' => max(array_column($perLocale, 'cls')),
            ];
        }

        return [
            'label' => $trend['templates'][$templateKey]['label'],
            'path' => $trend['templates'][$templateKey]['path'],
            'runs' => $runs,
            'locales' => $locales,
            'strategies' => $strategies,
            'table' => $table,
            'fieldState' => is_string($fieldState) ? $fieldState : 'none',
            'field' => $field,
        ];
    }

    // ── internals ────────────────────────────────────────────────

    private function localeOf(string $url): string
    {
        return str_contains($url, '/de/') ? 'de' : 'en';
    }

    /**
     * A list-typed config value ('cwv.locales' / 'cwv.strategies'),
     * narrowed from config's mixed with a committed fallback.
     *
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function configList(string $key, array $fallback): array
    {
        $value = config($key);

        if (! is_array($value)) {
            return $fallback;
        }

        /** @var list<string> $list */
        $list = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list === [] ? $fallback : $list;
    }

    /**
     * Latest + previous score per (template, strategy, locale) — the
     * flat check list the heat grid and KPIs consume, plus the
     * per-template lane view models. Rows must be fetched_at-ordered:
     * "prev" is whatever the key held before the latest overwrite.
     *
     * @param  list<array{template: string, locale: string, strategy: string, score: int|null, date: string, field_state: string}>  $rows
     * @param  list<string>  $runs
     * @return array{0: list<Check>, 1: array<string, Template>}
     */
    private function buildChecksAndTemplates(array $rows, array $runs): array
    {
        /** @var array<string, Check> $byKey */
        $byKey = [];

        /** @var array<string, list<array{date: string, score: int}>> $seriesByKey */
        $seriesByKey = [];

        foreach ($rows as $row) {
            if ($row['score'] === null) {
                continue;
            }

            $key = $row['template'].'|'.$row['strategy'].'|'.$row['locale'];
            $byKey[$key] = [
                'template' => $row['template'],
                'locale' => $row['locale'],
                'strategy' => $row['strategy'],
                'score' => $row['score'],
                'date' => $row['date'],
                'prev' => $byKey[$key]['score'] ?? null,
            ];
            $seriesByKey[$key][] = ['date' => $row['date'], 'score' => $row['score']];
        }

        /** @var list<Check> $checks */
        $checks = array_values($byKey);

        /** @var array<string, Template> $templates */
        $templates = [];

        /** @var array<string, string> $configTemplates */
        $configTemplates = config('cwv.templates');

        foreach ($configTemplates as $key => $path) {
            $lanes = [];

            $configStrategies = self::configList('cwv.strategies', ['mobile', 'desktop']);

            foreach ($configStrategies as $strategy) {
                $lanes[$strategy] = [
                    'series' => $this->alignToRuns($seriesByKey, $key, $strategy, $runs),
                    'latest' => $this->laneLatest($checks, $key, $strategy),
                ];
            }

            $worst = $this->laneWorst($checks, $key, null);

            if ($worst === null) {
                continue;
            }

            $templates[$key] = [
                'label' => self::TEMPLATE_LABELS[$key] ?? Str::headline($key),
                'path' => $path,
                'lanes' => $lanes,
                'worst' => $worst,
                'band' => self::band($worst['score']),
            ];
        }

        return [$checks, $templates];
    }

    /**
     * Per-locale series aligned to the run axis: null where the run
     * has no score for this (template, strategy, locale).
     *
     * @param  array<string, list<array{date: string, score: int}>>  $seriesByKey
     * @param  list<string>  $runs
     * @return array<string, list<?int>>
     */
    private function alignToRuns(array $seriesByKey, string $template, string $strategy, array $runs): array
    {
        /** @var array<string, list<?int>> $aligned */
        $aligned = [];

        $configLocales = self::configList('cwv.locales', ['en', 'de']);

        foreach ($configLocales as $locale) {
            /** @var Collection<string, int> $byDate */
            $byDate = collect($seriesByKey[$template.'|'.$strategy.'|'.$locale] ?? [])
                ->mapWithKeys(fn (array $point): array => [$point['date'] => $point['score']]);

            $aligned[$locale] = array_map(
                fn (string $date): ?int => $byDate->get($date),
                $runs,
            );
        }

        return $aligned;
    }

    /**
     * The lane's headline: worst (min) of the two locales' latest
     * scores, with the worst previous-run score for the delta chip.
     * Null when either locale has never been measured.
     *
     * @param  list<Check>  $checks
     * @return Lane|null
     */
    private function laneLatest(array $checks, string $template, string $strategy): ?array
    {
        /** @var array<string, Check> $perLocale */
        $perLocale = [];

        $configLocales = self::configList('cwv.locales', ['en', 'de']);

        foreach ($configLocales as $locale) {
            $match = $this->findCheck($checks, $template, $strategy, $locale);

            if ($match === null) {
                return null;
            }

            $perLocale[$locale] = $match;
        }

        $worst = $this->laneWorst($checks, $template, $strategy);

        if ($worst === null) {
            return null;
        }

        /** @var list<int> $prevScores */
        $prevScores = [];

        foreach ($perLocale as $check) {
            if ($check['prev'] !== null) {
                $prevScores[] = $check['prev'];
            }
        }

        return [
            'score' => $worst['score'],
            'en' => $perLocale['en']['score'],
            'de' => $perLocale['de']['score'],
            'prev' => $prevScores === [] ? null : min($prevScores),
        ];
    }

    /**
     * The lowest-scoring check within the given template/strategy (either
     * unconstrained), or null when nothing matches.
     *
     * @param  list<Check>  $checks
     * @return Check|null
     */
    private function laneWorst(array $checks, ?string $template, ?string $strategy): ?array
    {
        $worst = null;

        foreach ($checks as $check) {
            if ($template !== null && $check['template'] !== $template) {
                continue;
            }

            if ($strategy !== null && $check['strategy'] !== $strategy) {
                continue;
            }

            if ($worst === null || $check['score'] < $worst['score']) {
                $worst = $check;
            }
        }

        return $worst;
    }

    /**
     * Fleet KPIs from the flat check list: threshold pass count (with
     * the previous run's count for the delta), desktop/mobile
     * averages, worst check, and the covered window.
     *
     * @param  list<Check>  $checks
     * @return Kpis
     */
    private function kpis(array $checks): array
    {
        if ($checks === []) {
            return ['passing' => 0, 'total' => 0, 'prevPassing' => 0, 'desktopAvg' => 0, 'prevDesktopAvg' => null, 'mobileAvg' => 0, 'prevMobileAvg' => null, 'worst' => null, 'window' => ''];
        }

        $threshold = self::threshold();

        /** @param list<Check> $list @param 'score'|'prev' $field */
        $average = function (array $list, string $field): ?int {
            $scores = [];

            foreach ($list as $check) {
                if ($check[$field] !== null) {
                    $scores[] = $check[$field];
                }
            }

            return $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
        };

        /** @var list<Check> $desktop */
        $desktop = array_values(array_filter($checks, fn (array $c): bool => $c['strategy'] === 'desktop'));
        /** @var list<Check> $mobile */
        $mobile = array_values(array_filter($checks, fn (array $c): bool => $c['strategy'] === 'mobile'));

        /** @var list<Check> $passing */
        $passing = array_values(array_filter($checks, fn (array $c): bool => $c['score'] >= $threshold));
        /** @var list<Check> $prevPassing */
        $prevPassing = array_values(array_filter($checks, fn (array $c): bool => ($c['prev'] ?? $c['score']) >= $threshold));

        /** @var list<string> $dates */
        $dates = [];

        foreach ($checks as $check) {
            $dates[] = $check['date'];
        }

        $dates = array_values(array_unique($dates));
        $window = $dates[0].' → '.$dates[count($dates) - 1];

        return [
            'passing' => count($passing),
            'total' => count($checks),
            'prevPassing' => count($prevPassing),
            'desktopAvg' => $average($desktop, 'score') ?? 0,
            'prevDesktopAvg' => $average($desktop, 'prev'),
            'mobileAvg' => $average($mobile, 'score') ?? 0,
            'prevMobileAvg' => $average($mobile, 'prev'),
            'worst' => $this->laneWorst($checks, null, null),
            'window' => $window,
        ];
    }

    /**
     * @param  list<Check>  $checks
     * @return Check|null
     */
    private function findCheck(array $checks, string $template, string $strategy, string $locale): ?array
    {
        foreach ($checks as $check) {
            if ($check['template'] === $template && $check['strategy'] === $strategy && $check['locale'] === $locale) {
                return $check;
            }
        }

        return null;
    }
}
