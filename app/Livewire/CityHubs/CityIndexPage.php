<?php

namespace App\Livewire\CityHubs;

use App\Services\CityDirectoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Public city-hub index page at /{locale}/cities (M066/S06/T04).
 *
 * City hubs were previously reachable only through the discovery portal's
 * curated featured rail (62-04 T06) and the sitemap — no crawlable page
 * listed them all. This index closes that gap: every city cluster the
 * directory currently considers hub-worthy renders as one card linking to
 * its hub, so the programmatic-SEO asset has a stable front door for
 * visitors, the footer, and crawlers alike.
 *
 * The listing asks CityDirectoryService::qualifyingCities() — the same
 * single authority the hub guard, the sitemap, and the featured rail use
 * — so "which cities get a hub" can never drift between surfaces. That
 * set already includes curated cities (featured rows force-qualify over
 * both thresholds, MEM995) and already excludes hidden ones, so no extra
 * curation filtering is needed here. Featured cities sort first, then
 * alphabetical by display name.
 *
 * Deliberately unpaginated: qualification thresholds (upcoming public
 * activity OR verified venues) bound the set to genuinely active scenes,
 * the same bound the cities sitemap already iterates.
 */
#[Layout('components.public-layout')]
class CityIndexPage extends Component
{
    public function render(): View
    {
        $cities = app(CityDirectoryService::class)
            ->qualifyingCities()
            // Featured curation first, then alphabetical — the rail's
            // "curated local scenes" promise carries into the full list.
            // The documented multi-comparison form; data_get reads
            // CitySummary's public readonly props directly.
            ->sortBy([['featured', 'desc'], ['city', 'asc']])
            ->values();

        seo()->for(new SEOData(
            title: __('city-hubs.seo_title_index'),
            description: __('city-hubs.seo_description_index'),
        ));

        // Structured render log, once per page load (Livewire update
        // requests carry the X-Livewire header — same guard as
        // CityHubPage::trackPageView, so re-renders never double-count).
        // Tracks how the new front door performs as cities come and go.
        if (! request()->hasHeader('X-Livewire')) {
            Log::info('cityhub.index_rendered', [
                'city_count' => $cities->count(),
            ]);
        }

        return view('livewire.city-hubs.city-index-page', [
            'cities' => $cities,
        ]);
    }
}
