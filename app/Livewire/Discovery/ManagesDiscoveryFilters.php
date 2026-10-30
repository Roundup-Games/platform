<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\VibeFlag;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Traits\FoldsCanonicalToCityHub;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Shared filter lifecycle for Discovery Livewire components.
 *
 * Provides the common filter properties, mount initialization (language,
 * vibe preferences), updating hooks, radius setter, and event listeners
 * used by all three discovery pages.
 *
 * Consuming components must define: public int $displayCount = 12;
 * and implement discoveryFilterSurface() declaring their page-specific
 * filter extras (one of the DiscoveryFilters::SURFACE_* sets).
 *
 * Usage:
 *   class MyDiscoveryPage extends Component {
 *       use ManagesDiscoveryFilters;
 *       public int $displayCount = 12;
 *   }
 */
trait ManagesDiscoveryFilters
{
    // Canonical folding for ?city= (MEM997) lives in the shared trait the
    // events listing also consumes — one implementation, two surfaces.
    use FoldsCanonicalToCityHub;

    // ── Shared filter properties ───────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?string $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    /** @var array<int, int|string> */
    #[Url]
    public array $vibe_flags = [];

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', for VibePreferencePicker */
    public array $vibePreferences = [];

    #[Url]
    public string $language = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    #[Url]
    public string $price = '';

    // ── Proximity filter ───────────────────────────────

    /** @var float Search radius in km (0 = no proximity filter) */
    #[Url(as: 'radius')]
    public float $radius = 0;

    /** @var bool Whether results came from the wider fallback radius */
    public bool $usingFallbackRadius = false;

    // ── City filter (M062 62-03) ───────────────────────

    /**
     * URL-addressable city slug (?city=koeln). Backed by a city hub: the
     * value is only applied when CityDirectoryService resolves it to a
     * single qualifying city cluster, and any qualifying ?city= folds the
     * page's canonical to that city's hub (see citySeo()).
     */
    #[Url]
    public ?string $city = null;

    // ── Lifecycle ──────────────────────────────────────

    public function mountManagesDiscoveryFilters(): void
    {
        if ($this->language === '') {
            $this->language = $this->defaultFilterLanguage();
        }

        $user = Auth::user();

        // Build vibePreferences from URL vibe_flags (all treated as favorites)
        foreach (VibeFlag::cases() as $flag) {
            if (in_array($flag->value, $this->vibe_flags, true)) {
                $this->vibePreferences[$flag->value] = 'favorite';
            } else {
                $this->vibePreferences[$flag->value] = null;
            }
        }

        // Pre-select vibe flags from user preferences (only if no URL values already set)
        if ($this->vibe_flags === [] && $user !== null) {
            $resolvedVibes = $user->resolvedVibePreferences();
            $rawFavorites = is_array($resolvedVibes['favorites'] ?? null) ? $resolvedVibes['favorites'] : [];
            $favorites = array_values(array_filter($rawFavorites, fn (mixed $v) => is_string($v) || is_int($v)));
            if ($favorites !== []) {
                foreach ($favorites as $flagValue) {
                    $flagKey = (string) $flagValue;
                    $this->vibePreferences[$flagKey] = 'favorite';
                }
                $this->vibe_flags = $favorites;
            }
        }
    }

    // ── Shared updating hooks ──────────────────────────

    public function updatingSearch(): void
    {
        $this->displayCount = 12;
    }

    public function updatingGameSystemId(): void
    {
        $this->displayCount = 12;
    }

    public function updatingExperienceLevel(): void
    {
        $this->displayCount = 12;
    }

    public function updatingLanguage(): void
    {
        $this->displayCount = 12;
    }

    public function updatingPrice(): void
    {
        $this->displayCount = 12;
    }

    public function updatingRadius(): void
    {
        $this->displayCount = 12;
    }

    public function updatingCity(): void
    {
        $this->displayCount = 12;
    }

    // ── City-filter SEO ────────────────────────────────
    //
    // citySeo() moved to App\Traits\FoldsCanonicalToCityHub (composed
    // above) when the events listing adopted the same canonical folding.

    // ── Filter surface + lifecycle semantics ──────────

    /**
     * Page-specific filter property names beyond the shared base — one of
     * the DiscoveryFilters::SURFACE_* sets. The shared clearFilters() and
     * hasActiveFilters() below both derive from this single declaration, so
     * the reset list and the active check can never drift apart.
     *
     * @return array<int, string>
     */
    abstract protected function discoveryFilterSurface(): array;

    /**
     * Reset every filter on this page's surface to its neutral default.
     * The property list comes from DiscoveryFilters::resettableProperties()
     * so it is identical to the active-check surface by construction.
     */
    public function clearFilters(): void
    {
        $this->reset(DiscoveryFilters::resettableProperties(
            $this->discoveryFilterSurface(),
        ));
        $this->usingFallbackRadius = false;
        $this->displayCount = 12;

        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
    }

    /**
     * Whether any filter on this page's surface is APPLIED — drives the UI:
     * the active-filter chip, the Clear All affordance, and the filtered
     * empty-state copy.
     *
     * The language comparison uses the app locale: a viewer whose preferred
     * language differs from the request locale has their feed narrowed by
     * that preference, and the UI must say so (chip + "adjust your filters"
     * empty state) — hiding it left a narrowed feed with no visible cause
     * and no way to discover the empty state is filter-driven.
     */
    public function hasActiveFilters(): bool
    {
        return DiscoveryFilters::hasActiveFilters(
            $this,
            $this->discoveryFilterSurface(),
            app()->getLocale(),
        );
    }

    /**
     * Whether the viewer has SELECTED a filter — the analytics-gating
     * counterpart to {@see hasActiveFilters()}.
     *
     * Language counts as selected only when it differs from the mount-time
     * default (preferred language, else app locale): an untouched page for
     * a preferred-language viewer must not fire discovery.search, even
     * though its feed is narrowed and hasActiveFilters() is true.
     */
    public function hasUserSelectedFilters(): bool
    {
        return DiscoveryFilters::hasActiveFilters(
            $this,
            $this->discoveryFilterSurface(),
            $this->defaultFilterLanguage(),
        );
    }

    /**
     * The language filter's neutral default: the authenticated user's
     * preferred language when set, else the app locale.
     *
     * Single source for BOTH the mount-time seeding and the selection-aware
     * analytics comparison ({@see hasUserSelectedFilters()}) — mount seeds
     * from the user's preference, so "did the user select anything?" must
     * compare against that same default. The UI check
     * ({@see hasActiveFilters()}) deliberately uses the app locale instead:
     * an applied-but-not-selected preference still narrows the feed and
     * must stay visible to the viewer.
     */
    protected function defaultFilterLanguage(): string
    {
        $preferred = Auth::user()?->preferred_language;

        return $preferred !== null ? $preferred->value : app()->getLocale();
    }

    // ── Search origin ─────────────────────────────────

    /**
     * Resolve the proximity-search origin: the browser guest location when
     * present, else the logged-in user's saved location. One implementation
     * shared by all three discovery pages (was a 9-line copy-paste per
     * render(); GuestLocationTest covers the DiscoveryPage path).
     *
     * Requires HasGuestLocation on the consuming component.
     *
     * @return array{0: ?float, 1: ?float, 2: bool} [lat, lng, hasLocation]
     */
    protected function resolveSearchOrigin(): array
    {
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat;
        $lng = $this->guestLng;

        // Fallback to the logged-in user's saved location when browser geolocation is unavailable
        $locationId = Auth::user()?->location_id;
        if (! $hasLocation && $locationId !== null && $locationId !== '') {
            $userLocation = Location::find($locationId);
            if ($userLocation !== null) {
                $lat = (float) $userLocation->latitude;
                $lng = (float) $userLocation->longitude;
                $hasLocation = true;
            }
        }

        return [$lat, $lng, $hasLocation];
    }

    // ── Shared actions ─────────────────────────────────

    public function setRadius(float $radius): void
    {
        if ($radius !== 0.0
            && ! in_array($radius, array_map(fn (int $option): float => (float) $option, DiscoveryQueryService::RADIUS_OPTIONS), true)) {
            return;
        }
        $this->radius = $radius;
        $this->usingFallbackRadius = false;
        $this->displayCount = 12;
    }

    // ── Shared event listeners ─────────────────────────

    #[On('value-updated')]
    public function onGameSystemUpdated(mixed $value): void
    {
        $this->game_system_id = is_string($value) || $value === null ? $value : null;
        $this->displayCount = 12;
    }

    /**
     * @param  array<string, string|null>  $preferences
     */
    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
        // Extract only favorites for the query filter
        $this->vibe_flags = collect($preferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->values()
            ->all();
        $this->displayCount = 12;
    }
}
