<?php

namespace App\Livewire\CityHubs;

use App\Dto\CitySummary;
use App\Services\CityDirectoryService;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

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
    /**
     * Guard-rejection reasons for the structured cityhub.rejected log —
     * the full rejection taxonomy (slice verification: guard rejections
     * log their 404 reason).
     */
    public const REJECT_NOT_FOUND = 'not_found';

    public const REJECT_BELOW_THRESHOLD = 'below_threshold';

    public const REJECT_AMBIGUOUS = 'ambiguous';

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
     * Every rejection is logged as cityhub.rejected with its reason
     * before the abort (T04). Re-run on later Livewire renders (the
     * service caches per city, so this is a cache hit) so a cluster that
     * stops qualifying mid-session cannot keep rendering a stale hub.
     */
    protected function resolveCity(): CitySummary
    {
        if ($this->city instanceof CitySummary) {
            return $this->city;
        }

        $directory = app(CityDirectoryService::class);
        $summary = $directory->resolveCity($this->slug);

        if ($summary === null) {
            // resolveStatus() re-reads the same cached resolution (no extra
            // queries) and distinguishes the two null outcomes for the log.
            $this->rejectHub($directory->resolveStatus($this->slug) === CityDirectoryService::STATUS_AMBIGUOUS
                ? self::REJECT_AMBIGUOUS
                : self::REJECT_NOT_FOUND);
        }

        if (! $directory->isQualifying($summary)) {
            $this->rejectHub(self::REJECT_BELOW_THRESHOLD);
        }

        return $this->city = $summary;
    }

    public function render(): View
    {
        $city = $this->resolveCity();
        $directory = app(CityDirectoryService::class);

        $sessions = $directory->upcomingSessions($city);
        $venues = $directory->verifiedVenues($city);

        seo()->for(new SEOData(
            title: __('city-hubs.seo.title', ['city' => $city->city]),
            description: __('city-hubs.seo.description', ['city' => $city->city]),
            // Defense-in-depth: pin the clean hub URL so query-param variants
            // can never drift from the canonical. The global SEODataTransformer
            // only fills canonical_url when null (URL::to(request()->path())),
            // and hreflang alternates derive from the request path itself — the
            // hub path — so they stay correct without explicit alternates here.
            canonical_url: route('city-hubs.show', ['locale' => app()->getLocale(), 'slug' => $city->slug]),
        ));

        // Page-view observability fires once per page load. Livewire update
        // requests carry the X-Livewire header (Livewire's own
        // isLivewireRequest() check); component-update re-renders are not
        // page views and must not double-count.
        if (! request()->hasHeader('X-Livewire')) {
            $this->trackPageView($city, $sessions->count(), $venues->count());
        }

        return view('livewire.city-hubs.city-hub-page', [
            'city' => $city,
            'sessions' => $sessions,
            'venues' => $venues,
        ]);
    }

    /**
     * Structured guard-rejection log, then the real 404. Logged BEFORE the
     * abort so every rejected request — crawler probes included — is
     * visible with its reason (not_found, below_threshold, ambiguous).
     */
    private function rejectHub(string $reason): never
    {
        Log::info('cityhub.rejected', [
            'slug' => $this->slug,
            'reason' => $reason,
        ]);

        abort(404);
    }

    /**
     * Structured render log plus the consent-gated PostHog page-view event
     * (cityhub.viewed). Complements the consent-gated JS SDK's automatic
     * $pageview: the server-side event covers authenticated users AND
     * consented guests (the primary programmatic-SEO audience) under a
     * namespaced name, so the two streams never double-count. Analytics can
     * never break the page: every guard no-ops silently and
     * PostHogClient::capture is internally try/catch-wrapped.
     */
    private function trackPageView(CitySummary $city, int $sessionCount, int $venueCount): void
    {
        Log::info('cityhub.rendered', [
            'slug' => $city->slug,
            'city' => $city->city,
            'session_count' => $sessionCount,
            'venue_count' => $venueCount,
        ]);

        $posthog = app(PostHogClient::class);

        if (! $posthog->isEnabled() || ! app(PostHogConsentChecker::class)->hasAnalyticsConsent()) {
            return;
        }

        $user = Auth::user();

        $posthog->capture([
            // Same distinctId posture as the rest of the app: the opaque user
            // id when authenticated; for guests a cityhub-prefixed IP+UA
            // digest (the link: fingerprint pattern from RecordShortLinkHit)
            // — stable per visitor without storing PII.
            'distinctId' => $user !== null
                ? (string) $user->id
                : 'cityhub:'.hash('xxh128', (string) request()->ip().(string) request()->userAgent()),
            'event' => 'cityhub.viewed',
            'properties' => [
                'slug' => $city->slug,
                'city' => $city->city,
                'locale' => app()->getLocale(),
                'session_count' => $sessionCount,
                'venue_count' => $venueCount,
                'is_authenticated' => $user !== null,
            ],
        ]);
    }
}
