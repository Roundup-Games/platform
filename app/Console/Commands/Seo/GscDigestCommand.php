<?php

namespace App\Console\Commands\Seo;

use App\Models\GscSnapshot;
use App\SEO\SearchConsole\GscClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Weekly Google Search Console digest snapshot (SEO track 2026-10-15).
 *
 * Stores one GscSnapshot row per run: rolling 28-day totals, the previous
 * 28-day window for deltas, and the top 20 queries and pages. Scheduled
 * Mondays 06:00 (after data settles); the Search Performance admin page
 * renders the trend. Idempotent per window — re-running the same days
 * updates the existing snapshot row instead of stacking duplicates.
 */
#[Signature('seo:gsc-digest')]
#[Description('Store a Google Search Console digest snapshot (rolling 28 days)')]
class GscDigestCommand extends Command
{
    private const WINDOW_DAYS = 28;

    public function handle(GscClient $gsc): int
    {
        if (! $gsc->isEnabled()) {
            $this->info('GSC credentials not configured — nothing to do.');

            return self::SUCCESS;
        }

        $end = now()->subDay()->toDateString();
        $start = now()->subDays(self::WINDOW_DAYS)->toDateString();
        $previousStart = now()->subDays(self::WINDOW_DAYS * 2)->toDateString();
        $previousEnd = now()->subDays(self::WINDOW_DAYS + 1)->toDateString();

        $totals = $gsc->totals($start, $end);

        if ($totals === null) {
            $this->components->error('GSC query failed — see log (gsc.error / gsc.request_failed).');

            return self::FAILURE;
        }

        $previous = $gsc->totals($previousStart, $previousEnd) ?? [
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'position' => 0,
        ];

        $topQueries = $gsc->top('query', $start, $end) ?? [];
        $topPages = $gsc->top('page', $start, $end) ?? [];

        GscSnapshot::updateOrCreate(
            ['range_start' => $start, 'range_end' => $end],
            [
                'clicks' => $totals['clicks'],
                'impressions' => $totals['impressions'],
                'ctr' => $totals['ctr'],
                'position' => $totals['position'],
                'previous_clicks' => $previous['clicks'],
                'previous_impressions' => $previous['impressions'],
                'top_queries' => $topQueries,
                'top_pages' => $topPages,
            ],
        );

        $clicksDelta = $this->delta($totals['clicks'], $previous['clicks']);
        $impressionsDelta = $this->delta($totals['impressions'], $previous['impressions']);

        $this->components->twoColumnDetail("{$start} … {$end}", sprintf(
            '%d clicks (%s), %d impressions (%s), pos %.1f',
            $totals['clicks'],
            $clicksDelta,
            $totals['impressions'],
            $impressionsDelta,
            $totals['position'],
        ));
        $this->components->twoColumnDetail('top queries', count($topQueries).' stored');
        $this->components->twoColumnDetail('top pages', count($topPages).' stored');

        return self::SUCCESS;
    }

    /**
     * Human delta with sign, '-' when there is no baseline yet.
     */
    private function delta(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? '+new' : '-';
        }

        $percent = (int) round((($current - $previous) / $previous) * 100);

        return sprintf('%+d%%', $percent);
    }
}
