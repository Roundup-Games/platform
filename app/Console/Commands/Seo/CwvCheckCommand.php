<?php

namespace App\Console\Commands\Seo;

use App\Models\CwvMeasurement;
use App\SEO\Cwv\PsiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Core Web Vitals check across the representative templates
 * (config/cwv.php) via the free PageSpeed Insights API — mobile and
 * desktop, every locale. Scheduled weekly (routes/console.php); also
 * runnable on demand: `php artisan cwv:check`.
 *
 * Every (template × locale × strategy) result is persisted append-only
 * (CwvMeasurement) so trends survive; any threshold breach logs a
 * structured cwv.degraded line that surfaces in error tracking.
 */
#[Signature('cwv:check {--template=* : Limit the check to one or more template keys}')]
#[Description('Measure Core Web Vitals for representative templates via the PageSpeed Insights API')]
class CwvCheckCommand extends Command
{
    public function handle(PsiClient $psi): int
    {
        if (! $psi->isEnabled()) {
            $this->info('PageSpeed Insights key not configured — nothing to do.');

            return self::SUCCESS;
        }

        $templates = self::stringMap(config('cwv.templates', []));
        $locales = self::stringValues(config('cwv.locales', ['en']));
        $strategies = self::stringValues(config('cwv.strategies', ['mobile']));
        $only = array_values(array_filter($this->option('template'), is_string(...)));

        if ($only !== []) {
            $templates = array_intersect_key($templates, array_flip($only));
        }

        $appUrl = config('app.url');
        $baseUrl = is_string($appUrl) ? rtrim($appUrl, '/') : '';
        $checked = 0;
        $degraded = 0;

        foreach ($templates as $template => $path) {
            foreach ($locales as $locale) {
                $url = "{$baseUrl}/{$locale}{$path}";

                foreach ($strategies as $strategy) {
                    $result = $psi->check($url, $strategy);

                    if ($result === null) {
                        $this->components->twoColumnDetail("{$template} [{$locale}/{$strategy}]", 'FAILED (see log)');

                        continue;
                    }

                    CwvMeasurement::create([
                        'template' => $template,
                        'url' => $url,
                        'strategy' => $strategy,
                        'performance_score' => $result['performance_score'],
                        'lcp_ms' => $result['lcp_ms'],
                        'inp_ms' => $result['inp_ms'],
                        'cls' => $result['cls'],
                        'field_data_state' => $result['field_data_state'],
                        'payload' => $result['payload'],
                        'fetched_at' => now(),
                    ]);

                    $checked++;

                    $breaches = $this->breaches($result);

                    if ($breaches !== []) {
                        $degraded++;
                        Log::warning('cwv.degraded', [
                            'template' => $template,
                            'locale' => $locale,
                            'strategy' => $strategy,
                            'breaches' => $breaches,
                        ]);
                        $this->components->twoColumnDetail(
                            "{$template} [{$locale}/{$strategy}]",
                            'perf '.($result['performance_score'] ?? '?').' — '.implode(', ', $breaches),
                        );

                        continue;
                    }

                    $this->components->twoColumnDetail(
                        "{$template} [{$locale}/{$strategy}]",
                        'perf '.($result['performance_score'] ?? '?').' — ok',
                    );
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('%d measurement(s) stored, %d degraded (thresholds in config/cwv.php).', $checked, $degraded));

        return self::SUCCESS;
    }

    /**
     * Threshold breaches for one result, keyed for the structured log.
     *
     * @param  array{performance_score: int|null, lcp_ms: int|null, inp_ms: int|null, cls: float|null}  $result
     * @return list<string>
     */
    private function breaches(array $result): array
    {
        $thresholds = config('cwv.thresholds', []);
        $thresholds = is_array($thresholds) ? $thresholds : [];
        $breaches = [];

        $performance = $result['performance_score'];
        $limit = $thresholds['performance_score'] ?? null;

        if (is_int($performance) && is_int($limit) && $performance < $limit) {
            $breaches[] = "performance {$performance}<{$limit}";
        }

        $lcp = $result['lcp_ms'];
        $lcpLimit = $thresholds['lcp_ms'] ?? null;

        if (is_int($lcp) && is_int($lcpLimit) && $lcp > $lcpLimit) {
            $breaches[] = "lcp {$lcp}ms>{$lcpLimit}ms";
        }

        $inp = $result['inp_ms'];
        $inpLimit = $thresholds['inp_ms'] ?? null;

        if (is_int($inp) && is_int($inpLimit) && $inp > $inpLimit) {
            $breaches[] = "inp {$inp}ms>{$inpLimit}ms";
        }

        $cls = $result['cls'];
        $clsLimit = $thresholds['cls'] ?? null;

        if (is_float($cls) && is_numeric($clsLimit) && $cls > (float) $clsLimit) {
            $breaches[] = "cls {$cls}>{$clsLimit}";
        }

        return $breaches;
    }

    /**
     * Config maps arrive as mixed — normalize to string keys and values
     * (template key => locale-relative path).
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    /**
     * Config lists arrive as mixed — normalize to a list of strings.
     *
     * @return list<string>
     */
    private static function stringValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }
}
