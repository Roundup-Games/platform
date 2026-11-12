<?php

namespace App\Console\Dev;

use App\Models\CwvMeasurement;
use Illuminate\Console\Command;

/**
 * Local-demo seeder for the Core Web Vitals trend page: replays the
 * REAL production lab-score history (the four runs captured 2026-11-12
 * — 96 points across 6 templates × 2 locales × 2 strategies) and then
 * completes it with SIMULATED CrUX field data on the newest run, which
 * production does not have yet (the site is below CrUX's inclusion
 * threshold — every real row is field_data_state 'none').
 *
 * The simulation exists so the field tiles (LCP/INP/CLS) can be
 * browsed with plausible values: desktop better than mobile, the
 * game-system template the weakest, values scaled off each check's
 * real lab score so the story stays coherent. Deterministic — no
 * randomness, same output on every run.
 *
 * Dev-only: refuses to run outside local environments and wipes the
 * cwv_measurements table first (it IS the demo history).
 */
class CwvDemoSeedCommand extends Command
{
    protected $signature = 'dev:cwv-demo-seed';

    protected $description = 'Seed local demo Core Web Vitals data: real production lab history + simulated CrUX field metrics on the newest run';

    /**
     * Real lab scores from production (exported 2026-11-12):
     * date => template => strategy-locale => performance_score.
     *
     * @var array<string, array<string, array<string, int>>>
     */
    private const PRODUCTION_HISTORY = [
        '2026-10-15' => [
            'city-hub' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 95],
            'discovery-adventures' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 99, 'mobile-en' => 99],
            'discovery-board-games' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 96],
            'game-system' => ['desktop-de' => 99, 'desktop-en' => 99, 'mobile-de' => 87, 'mobile-en' => 87],
            'home' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 95, 'mobile-en' => 88],
            'static-pricing' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 97],
        ],
        '2026-10-19' => [
            'city-hub' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 96, 'mobile-en' => 97],
            'discovery-adventures' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 97],
            'discovery-board-games' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 97],
            'game-system' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 89, 'mobile-en' => 86],
            'home' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 93, 'mobile-en' => 95],
            'static-pricing' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 97, 'mobile-en' => 95],
        ],
        '2026-11-02' => [
            'city-hub' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 98, 'mobile-en' => 98],
            'discovery-adventures' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 100, 'mobile-en' => 97],
            'discovery-board-games' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 99, 'mobile-en' => 100],
            'game-system' => ['desktop-de' => 97, 'desktop-en' => 100, 'mobile-de' => 89, 'mobile-en' => 89],
            'home' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 95, 'mobile-en' => 98],
            'static-pricing' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 100, 'mobile-en' => 100],
        ],
        '2026-11-12' => [
            'city-hub' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 99, 'mobile-en' => 100],
            'discovery-adventures' => ['desktop-de' => 100, 'desktop-en' => 99, 'mobile-de' => 99, 'mobile-en' => 99],
            'discovery-board-games' => ['desktop-de' => 99, 'desktop-en' => 100, 'mobile-de' => 100, 'mobile-en' => 99],
            'game-system' => ['desktop-de' => 96, 'desktop-en' => 100, 'mobile-de' => 87, 'mobile-en' => 87],
            'home' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 99, 'mobile-en' => 99],
            'static-pricing' => ['desktop-de' => 100, 'desktop-en' => 100, 'mobile-de' => 100, 'mobile-en' => 100],
        ],
    ];

    public function handle(): int
    {
        if (! in_array(app()->environment(), ['local', 'testing', 'development'], true)) {
            $this->error('Refusing to run in ['.app()->environment().'] — this seeder wipes cwv_measurements and plants demo data.');

            return self::FAILURE;
        }

        CwvMeasurement::query()->delete();

        $runs = array_keys(self::PRODUCTION_HISTORY);
        $newestRun = end($runs);
        $created = 0;
        $fieldRows = 0;

        foreach (self::PRODUCTION_HISTORY as $date => $templates) {
            foreach ($templates as $template => $checks) {
                foreach ($checks as $strategyLocale => $score) {
                    [$strategy, $locale] = explode('-', $strategyLocale, 2);

                    /** @var array<string, string> $templatePaths */
                    $templatePaths = config('cwv.templates', []);
                    $path = $templatePaths[$template] ?? '/';

                    $withField = $date === $newestRun;
                    $field = $withField ? self::simulateField($score, $strategy) : ['lcp' => null, 'inp' => null, 'cls' => null];

                    CwvMeasurement::create([
                        'template' => $template,
                        'url' => 'https://roundup.games/'.$locale.$path,
                        'strategy' => $strategy,
                        'performance_score' => $score,
                        'lcp_ms' => $field['lcp'],
                        'inp_ms' => $field['inp'],
                        'cls' => $field['cls'],
                        'field_data_state' => $withField ? 'sufficient' : 'none',
                        'fetched_at' => $date.' 05:00:00',
                    ]);

                    $created++;
                    $fieldRows += $withField ? 1 : 0;
                }
            }
        }

        $this->info('Seeded '.$created.' cwv_measurements rows ('.count($runs).' runs; '.$fieldRows.' newest-run rows carry simulated field data).');

        return self::SUCCESS;
    }

    /**
     * Deterministic plausible field metrics for one (score, strategy):
     * values scale off the lab score — a weaker lab score reads as a
     * weaker visitor experience — with mobile strictly worse than
     * desktop, the way real CrUX splits. Clamped into realistic
     * ranges and rounded to CrUX-style precision (LCP/INP to 10ms,
     * CLS to 3 decimals).
     *
     * @return array{lcp: int, inp: int, cls: float}
     */
    private static function simulateField(int $score, string $strategy): array
    {
        $weakness = 100 - $score; // 0..13 in the real history

        $lcp = $strategy === 'mobile'
            ? 2_100 + $weakness * 60      // 2100–2880ms
            : 1_150 + $weakness * 35;     // 1150–1605ms
        $inp = $strategy === 'mobile'
            ? 140 + $weakness * 12        // 140–296ms
            : 45 + $weakness * 6;         // 45–123ms
        $cls = round(0.02 + $weakness * 0.009, 3);

        return [
            'lcp' => (int) (round($lcp / 10) * 10),
            'inp' => (int) (round($inp / 10) * 10),
            'cls' => min(0.25, $cls),
        ];
    }
}
