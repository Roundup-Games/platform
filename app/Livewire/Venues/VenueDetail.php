<?php

namespace App\Livewire\Venues;

use App\Models\Campaign;
use App\Models\Location;
use App\Services\LocationDisclosureService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public venue page at /{locale}/venue/{slug} (M053/S02).
 *
 * Mirrors the proven TeamDetail full-page pattern but adds a safety-critical
 * 404 gate: only verified commercial venues render. The gate is delegated to
 * {@see LocationDisclosureService::isPublicVenuePage()} — the single named
 * authority — so the "who gets a public page" rule can never drift across
 * surfaces (this route, <x-venue-link>, and the venues sitemap all call it).
 *
 * The page is public for everyone — authenticated users are NOT redirected
 * (unlike PublicGameDetail): a venue page is a directory entry, not an
 * entity with join/apply affordances, so the public view is canonical.
 *
 * Activity aggregation is query-time (no denormalized columns): upcoming and
 * past public sessions split on now(), plus active/completed public campaigns.
 * Each list is capped (limit 10) to bound render cost — a busy venue still
 * produces a bounded page.
 */
#[Layout('components.public-layout')]
class VenueDetail extends Component
{
    public Location $location;

    public function mount(string $slug): void
    {
        $location = Location::where('slug', $slug)->firstOrFail();

        // MEM717 invariant: only verified commercial venues get a public page.
        // abort_unless keeps the failure path a standard Laravel 404 (logged by
        // the exception handler) so no private/unverified/`other` location is
        // ever reachable from an indexed route.
        abort_unless(
            app(LocationDisclosureService::class)->isPublicVenuePage($location),
            404,
        );

        $this->location = $location;
    }

    public function render(): View
    {
        $this->location->load(['managedBy']);

        // Upcoming public sessions: scheduled and in the future.
        // scopePublic() filters visibility; scopeScheduled() filters status.
        $upcomingSessions = $this->location->games()
            ->public()
            ->scheduled()
            ->where('date_time', '>', now())
            ->orderBy('date_time')
            ->limit(10)
            ->get();

        // Past public sessions: any non-canceled status that already happened
        // (completed sessions are the valuable history; canceled ones excluded).
        $pastSessions = $this->location->games()
            ->public()
            ->where('status', '!=', 'canceled')
            ->where('date_time', '<=', now())
            ->orderByDesc('date_time')
            ->limit(10)
            ->get();

        // Active public campaigns currently running at the venue.
        /** @var Collection<int, Campaign> $activeCampaigns */
        $activeCampaigns = $this->location->campaigns()
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->limit(10)
            ->get();

        // Completed public campaigns — venue history at the coarser campaign grain.
        /** @var Collection<int, Campaign> $completedCampaigns */
        $completedCampaigns = $this->location->campaigns()
            ->where('visibility', 'public')
            ->where('status', 'completed')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        seo()->for($this->location);

        return view('livewire.venues.venue-detail', [
            'location' => $this->location,
            'upcomingSessions' => $upcomingSessions,
            'pastSessions' => $pastSessions,
            'activeCampaigns' => $activeCampaigns,
            'completedCampaigns' => $completedCampaigns,
        ]);
    }
}
