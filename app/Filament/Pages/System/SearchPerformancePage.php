<?php

namespace App\Filament\Pages\System;

use App\Models\CwvMeasurement;
use App\Models\GscSnapshot;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Search Performance admin page (SEO track 2026-10-15): the SEO
 * measurement loop in one view — Google Search Console digest trend
 * (weekly snapshots from seo:gsc-digest), latest Core Web Vitals
 * per template (cwv:check), and the IndexNow pipeline state.
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
            'cwv' => $this->latestCwvPerTemplate(),
        ];
    }

    /**
     * Latest measurement per (template, strategy) — the freshest CWV
     * reading for every monitored template.
     *
     * @return list<array{template: string, strategy: string, performance: int|null, lcp_ms: int|null, inp_ms: int|null, cls: float|null, url: string, fetched_at: string}>
     */
    private function latestCwvPerTemplate(): array
    {
        $rows = [];

        $measurements = CwvMeasurement::query()
            ->whereIn('id', CwvMeasurement::selectRaw('DISTINCT ON (template, strategy) id')
                ->from('cwv_measurements as latest')
                ->orderBy('template')
                ->orderBy('strategy')
                ->orderByDesc('fetched_at')
                ->select('latest.id'))
            ->orderBy('template')
            ->orderBy('strategy')
            ->get();

        foreach ($measurements as $measurement) {
            $rows[] = [
                'template' => $measurement->template,
                'strategy' => $measurement->strategy,
                'performance' => $measurement->performance_score,
                'lcp_ms' => $measurement->lcp_ms,
                'inp_ms' => $measurement->inp_ms,
                'cls' => $measurement->cls,
                'url' => $measurement->url,
                'fetched_at' => $measurement->fetched_at !== ''
                    ? Carbon::parse($measurement->fetched_at)->toDateTimeString('minute')
                    : '',
            ];
        }

        return $rows;
    }
}
