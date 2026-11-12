<?php

namespace App\Filament\Pages\System;

use App\SEO\CwvTrendService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Core Web Vitals trend page (2026-11-12): the visitor-experience
 * evolution view over the append-only cwv_measurements history —
 * fleet KPIs, the 24-check heat grid, per-template desktop/mobile
 * lanes with sparklines, and a click-through drill-down per template
 * (locale filterable) plus the LCP/INP/CLS tiles that light up once
 * CrUX field data exists.
 *
 * Split from Search Performance on purpose: the trend surface is
 * card-heavy and answers "how does the experience evolve", while the
 * search page answers "are we visible" — different questions, each
 * with room to breathe. Search Performance embeds the one-line
 * summary (CwvTrendService::summaryLine) and links here.
 *
 * Lab/field discipline: everything rendered is LAB (Lighthouse via
 * PSI) until field_data_state moves off 'none'; the field tiles say
 * so instead of showing dashes (missing data is not zero).
 */
class CoreWebVitalsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    // 13 — directly after Search Performance (12), its CWV successor.
    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'core-web-vitals';

    protected static ?string $title = 'Core Web Vitals';

    protected static ?string $navigationLabel = 'Core Web Vitals';

    protected string $view = 'filament.pages.system.core-web-vitals';

    /** Selected template key for the drill-down section ('' = none). */
    public string $selectedTemplate = '';

    /** Locale filter for the drill-down: all|en|de. */
    public string $localeFilter = 'all';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getViewData(): array
    {
        $trend = app(CwvTrendService::class)->trend();

        return [
            'trend' => $trend,
            'detail' => $this->selectedTemplate === ''
                ? null
                : app(CwvTrendService::class)->detail($this->selectedTemplate, $this->normalizedLocale()),
            'selectedTemplate' => $this->selectedTemplate,
            'localeFilter' => $this->localeFilter,
        ];
    }

    public function selectTemplate(string $key): void
    {
        /** @var array<string, string> $templates */
        $templates = config('cwv.templates');
        $this->selectedTemplate = array_key_exists($key, $templates) ? $key : '';
        $this->localeFilter = 'all';
    }

    public function clearTemplate(): void
    {
        $this->selectedTemplate = '';
    }

    public function setLocaleFilter(string $locale): void
    {
        $this->localeFilter = $this->isKnownLocale($locale) ? $locale : 'all';
    }

    /** @return 'all'|'en'|'de' */
    private function normalizedLocale(): string
    {
        return match ($this->localeFilter) {
            'en' => 'en',
            'de' => 'de',
            default => 'all',
        };
    }

    private function isKnownLocale(string $locale): bool
    {
        /** @var list<string> $locales */
        $locales = config('cwv.locales');

        return in_array($locale, ['all', ...$locales], true);
    }

    /**
     * Run dates as "15 Oct" for view headers — EN-only admin surface
     * (the Filament/ exemption from the K002 format_date rule).
     *
     * @param  list<string>  $runs
     * @return list<string>
     */
    public static function runLabels(array $runs): array
    {
        return array_map(fn (string $run): string => Carbon::parse($run)->format('d M'), $runs);
    }
}
