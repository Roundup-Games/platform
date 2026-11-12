<?php

namespace App\Filament\Pages\System;

use App\Models\GscSnapshot;
use App\SEO\CwvTrendService;
use App\SEO\LlmsTxtService;
use Filament\Pages\Page;

/**
 * Search Performance admin page (SEO track 2026-10-15): the SEO
 * measurement loop in one view — Google Search Console digest trend
 * (weekly snapshots from seo:gsc-digest), the Core Web Vitals fleet
 * summary (full trend view lives on CoreWebVitalsPage since
 * 2026-11-12), and the IndexNow pipeline state.
 */
class SearchPerformancePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    // 12 — after Scheduled Tasks (10) and Queue Structure (11), the queue
    // subsystem pages this shares the System group with.
    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'search-performance';

    protected static ?string $title = 'Search Performance';

    protected static ?string $navigationLabel = 'Search Performance';

    protected string $view = 'filament.pages.system.search-performance';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getViewData(): array
    {
        return [
            'latest' => GscSnapshot::query()->latest('range_end')->first(),
            'trend' => GscSnapshot::query()->orderBy('range_end')->limit(12)->get(),
            'cwvSummary' => app(CwvTrendService::class)->summaryLine(),
            'aiSurfaces' => $this->aiSurfacesData(),
        ];
    }

    /**
     * AI-surfaces section payload (78-05-T01/T02): the documented GSC
     * API gap context, agent-crawler posture links on the canonical
     * base URL, and the llms.txt fetch total — first-party telemetry
     * (hourly cache buckets incremented by the /llms.txt serve path,
     * summed over the last 7 days by LlmsTxtService).
     *
     * @return array{llmsTxtUrl: string, robotsTxtUrl: string, fetchesLast7Days: int}
     */
    private function aiSurfacesData(): array
    {
        // Request-independent base URL, config-driven exactly like
        // LlmsTxtService::baseUrl() — never url(), whose host follows
        // the incoming request.
        $url = config('app.url');
        $base = is_string($url) && $url !== '' ? rtrim($url, '/') : 'https://roundup.games';

        return [
            'llmsTxtUrl' => $base.'/llms.txt',
            'robotsTxtUrl' => $base.'/robots.txt',
            'fetchesLast7Days' => app(LlmsTxtService::class)->fetchCountsLast7Days(),
        ];
    }
}
