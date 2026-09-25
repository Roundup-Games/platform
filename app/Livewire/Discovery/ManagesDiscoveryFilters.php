<?php

namespace App\Livewire\Discovery;

use App\Enums\VibeFlag;
use App\Services\CityDirectoryService;
use App\Services\DiscoveryQueryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use RalphJSmit\Laravel\SEO\Support\AlternateTag;

/**
 * Shared filter lifecycle for Discovery Livewire components.
 *
 * Provides the common filter properties, mount initialization (language,
 * vibe preferences), updating hooks, radius setter, and event listeners
 * used by all three discovery pages.
 *
 * Consuming components must define: public int $displayCount = 12;
 *
 * Usage:
 *   class MyDiscoveryPage extends Component {
 *       use ManagesDiscoveryFilters;
 *       public int $displayCount = 12;
 *   }
 */
trait ManagesDiscoveryFilters
{
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
        $user = Auth::user();
        if (! $this->language) {
            $this->language = ($user?->preferred_language)
                ? $user->preferred_language->value
                : app()->getLocale();
        }

        // Build vibePreferences from URL vibe_flags (all treated as favorites)
        foreach (VibeFlag::cases() as $flag) {
            if (in_array($flag->value, $this->vibe_flags, true)) {
                $this->vibePreferences[$flag->value] = 'favorite';
            } else {
                $this->vibePreferences[$flag->value] = null;
            }
        }

        // Pre-select vibe flags from user preferences (only if no URL values already set)
        if (empty($this->vibe_flags) && $user) {
            $resolvedVibes = $user->resolvedVibePreferences();
            $rawFavorites = is_array($resolvedVibes['favorites'] ?? null) ? $resolvedVibes['favorites'] : [];
            $favorites = array_values(array_filter($rawFavorites, fn (mixed $v) => is_string($v) || is_int($v)));
            if (! empty($favorites)) {
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

    /**
     * Canonical folding + hreflang alternates for a qualifying ?city=.
     *
     * MEM997: any discovery URL with a city param canonicalizes to that
     * city's hub — all other params dropped — so filter permutations can
     * never become indexable URLs. Resolution and qualification flow
     * through CityDirectoryService (the single authority); a slug that is
     * unknown, ambiguous, or below the qualification thresholds is inert:
     * [null, null] keeps the global transformer's path-derived defaults,
     * so a canonical can never point at a hub that would 404.
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
        $summary = $directory->resolveCity($this->city);

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

    // ── Shared actions ─────────────────────────────────

    public function setRadius(float $radius): void
    {
        if ($radius != 0 && ! in_array($radius, DiscoveryQueryService::RADIUS_OPTIONS, false)) {
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
