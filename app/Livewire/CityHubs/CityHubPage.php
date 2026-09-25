<?php

namespace App\Livewire\CityHubs;

use App\Dto\CitySummary;
use App\Services\CityDirectoryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public city hub page at /{locale}/cities/{slug} (M062/S01).
 *
 * Programmatic-SEO landing page aggregating a city cluster's upcoming
 * public activity and verified venues: the sessions section merges
 * games (both discovery forks), campaigns, and events chronologically,
 * and the venues section lists public-venue-page locations via
 * <x-venue-link>. Mirrors the GameSystemDetail/VenueDetail full-page
 * pattern with a safety-critical 404 gate: only slugs that resolve to a
 * single qualifying city cluster render. CityDirectoryService is the
 * single authority for both resolution and thresholds, so the "which
 * cities get a hub" rule can never drift across surfaces (this route,
 * the sitemap, and cross-links all ask it).
 *
 * The route parameter is {slug} and mount() must match it by name —
 * Livewire binds route params to mount params by identifier (kebab-case
 * converts to camelCase, nothing else), the same shape as
 * game-systems.show and venues.detail.
 */
#[Layout('components.public-layout')]
class CityHubPage extends Component
{
    #[Locked]
    public string $slug = '';

    private ?CitySummary $city = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->resolveCity();
    }

    /**
     * Resolve the slug to a qualifying city summary or abort 404.
     *
     * Unknown slugs, ambiguous city names across regions
     * (CityDirectoryService::STATUS_AMBIGUOUS), and below-threshold
     * cities all fail identically: a real 404 — never a soft empty page.
     * Re-run on later Livewire renders (the service caches per city, so
     * this is a cache hit) so a cluster that stops qualifying mid-session
     * cannot keep rendering a stale hub.
     */
    protected function resolveCity(): CitySummary
    {
        if ($this->city instanceof CitySummary) {
            return $this->city;
        }

        $directory = app(CityDirectoryService::class);
        $summary = $directory->resolveCity($this->slug);

        abort_if($summary === null || ! $directory->isQualifying($summary), 404);

        return $this->city = $summary;
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $directory = app(CityDirectoryService::class);

        return view('livewire.city-hubs.city-hub-page', [
            'city' => $city,
            'sessions' => $directory->upcomingSessions($city),
            'venues' => $directory->verifiedVenues($city),
        ]);
    }
}
