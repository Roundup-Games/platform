<?php

namespace App\Livewire\Discovery;

use App\Enums\VibeFlag;
use App\Services\DiscoveryQueryService;
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

    // ── Lifecycle ──────────────────────────────────────

    public function mountManagesDiscoveryFilters(): void
    {
        $user = Auth::user();
        if (! $this->language) {
            $this->language = ($user && $user->preferred_language)
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
        if ($user && empty($this->vibe_flags)) {
            $resolvedVibes = $user->resolvedVibePreferences();
            if (! empty($resolvedVibes['favorites'])) {
                foreach ($resolvedVibes['favorites'] as $flagValue) {
                    $this->vibePreferences[$flagValue] = 'favorite';
                }
                $this->vibe_flags = $resolvedVibes['favorites'];
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
    public function onGameSystemUpdated($value): void
    {
        $this->game_system_id = $value;
        $this->displayCount = 12;
    }

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
