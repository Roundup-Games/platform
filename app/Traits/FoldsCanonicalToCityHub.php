<?php

namespace App\Traits;

use App\Services\CityDirectoryService;
use RalphJSmit\Laravel\SEO\Support\AlternateTag;

/**
 * Canonical folding for the URL-addressable ?city= filter (MEM997).
 *
 * Extracted from ManagesDiscoveryFilters when the events listing gained
 * the same city awareness (M066/S07/T01): any URL with a qualifying
 * ?city= canonicalizes to that city's hub — all other params dropped —
 * so filter permutations can never become indexable URLs. Resolution and
 * qualification flow through CityDirectoryService (the single authority);
 * a slug that is unknown, ambiguous, or below the qualification
 * thresholds is inert: [null, null] keeps the global transformer's
 * path-derived defaults, so a canonical can never point at a hub that
 * would 404.
 *
 * Consuming components must expose the city filter as a URL-addressable
 * nullable string property (the ManagesDiscoveryFilters shape):
 *
 *     #[Url]
 *     public ?string $city = null;
 *
 * (Same stub-property convention as DiscoveryUtilities's $date /
 * $category_ids stubs — the trait reads $this->city directly.)
 */
trait FoldsCanonicalToCityHub
{
    /**
     * Canonical folding + hreflang alternates for a qualifying ?city=.
     *
     * Resolution is cached per city (900s), so this per-render call is a
     * cache hit after the query layer's first resolve in the same request.
     *
     * @return array{0: string|null, 1: array<int, AlternateTag>|null}
     *                                                                 [canonical hub URL for the request locale, per-locale hub
     *                                                                 alternates + x-default] — both null when the filter is inert.
     */
    protected function citySeo(): array
    {
        if (! filled($this->city)) {
            return [null, null];
        }

        $directory = app(CityDirectoryService::class);
        $summary = $directory->resolveCity((string) $this->city);

        if ($summary === null || ! $directory->isQualifying($summary)) {
            return [null, null];
        }

        $hubUrl = fn (string $locale): string => route('city-hubs.show', [
            'locale' => $locale,
            'slug' => $summary->slug,
        ]);

        $locales = config('app.available_locales', ['en']);
        if (! is_array($locales)) {
            $locales = ['en'];
        }

        $alternates = [];
        foreach ($locales as $locale) {
            if (is_string($locale)) {
                $alternates[] = new AlternateTag($locale, $hubUrl($locale));
            }
        }
        $defaultLocale = is_string($locales[0] ?? null) ? $locales[0] : 'en';
        $alternates[] = new AlternateTag('x-default', $hubUrl($defaultLocale));

        return [$hubUrl(app()->getLocale()), $alternates];
    }
}
