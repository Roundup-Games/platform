<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\SafetyTool;
use App\Enums\VibeFlag;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class DiscoveryPage extends Component
{
    use HasGuestLocation;
    use WithPagination;

    // Mode
    #[Url]
    public string $mode = 'all';

    // Shared filters
    #[Url(as: 'q')]
    public string $search = '';
    #[Url]
    public ?string $game_system_id = null;
    #[Url]
    public string $experience_level = '';
    #[Url]
    public array $vibe_flags = [];
    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid' */
    public array $vibePreferences = [];
    #[Url]
    public array $safety_tools = [];
    #[Url]
    public string $language = '';
    #[Url]
    public ?string $complexity_min = null;
    #[Url]
    public ?string $complexity_max = null;
    #[Url]
    public string $price = '';
    #[Url]
    public array $category_ids = [];
    #[Url]
    public array $mechanic_ids = [];

    // Proximity
    #[Url(as: 'radius')]
    public float $radius = 0;
    public bool $usingFallbackRadius = false;

    // Entity-specific filters
    #[Url]
    public string $date = '';
    #[Url]
    public string $recurrence = '';

    // Lifecycle

    public function mount(): void
    {
        $user = Auth::user();
        if (!$this->language) {
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
            if (!empty($resolvedVibes['favorites'])) {
                foreach ($resolvedVibes['favorites'] as $flagValue) {
                    $this->vibePreferences[$flagValue] = 'favorite';
                }
                $this->vibe_flags = $resolvedVibes['favorites'];
            }
        }
    }

    // Updating hooks

    public function updating(): void
    {
        $this->resetPage();
    }

    // Actions

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->resetPage();
    }

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->resetPage();
    }

    public function setRecurrence(string $recurrence): void
    {
        $this->recurrence = $recurrence;
        $this->resetPage();
    }

    public function setRadius(float $radius): void
    {
        if ($radius != 0 && !in_array($radius, DiscoveryQueryService::RADIUS_OPTIONS, false)) {
            return;
        }
        $this->radius = $radius;
        $this->usingFallbackRadius = false;
        $this->resetPage();
    }

    public function toggleSafetyTool(string $tool): void
    {
        $this->safety_tools = $this->toggleArrayValue($this->safety_tools, $tool);
        $this->resetPage();
    }

    public function toggleCategory(string $categoryId): void
    {
        $this->category_ids = $this->toggleArrayValue($this->category_ids, $categoryId);
        $this->resetPage();
    }

    public function toggleMechanic(string $mechanicId): void
    {
        $this->mechanic_ids = $this->toggleArrayValue($this->mechanic_ids, $mechanicId);
        $this->resetPage();
    }

    private function toggleArrayValue(array $array, string $value): array
    {
        $index = array_search($value, $array, true);
        if ($index !== false) {
            unset($array[$index]);
            return array_values($array);
        }
        $array[] = $value;
        return $array;
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'safety_tools', 'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'recurrence', 'category_ids', 'mechanic_ids', 'radius',
        ]);
        $this->usingFallbackRadius = false;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
        $this->resetPage();
    }

    // Event listeners

    #[On('value-updated')]
    public function onGameSystemUpdated($value): void
    {
        $this->game_system_id = $value;
        $this->resetPage();
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
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || !empty($this->safety_tools)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || $this->recurrence
            || !empty($this->category_ids)
            || !empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // Render

    public function render()
    {
        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this)->toArray();
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat ?? null;
        $lng = $this->guestLng ?? null;

        $results = match ($this->mode) {
            'games' => $service->getGamesResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->date),
            'campaigns' => $service->getCampaignsResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->recurrence),
            default => tap(
                $service->getMergedResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->date, $this->recurrence),
                fn (array $r) => $this->usingFallbackRadius = $r['usingFallback'],
            )['results'],
        };

        return view('livewire.discovery.discovery-page', [
            'results' => $results,
            'recommendations' => $service->getRecommendations($user),
            'experienceLevels' => ExperienceLevel::cases(),
            'safetyToolGroups' => SafetyTool::grouped(),
            'languages' => ContentLanguage::cases(),
            'recurrenceOptions' => ['weekly', 'bi-weekly', 'monthly'],
            'curatedCategories' => $service->getCuratedCategories(),
            'curatedMechanics' => $service->getCuratedMechanics(),
            'radiusOptions' => DiscoveryQueryService::RADIUS_OPTIONS,
            'hasLocation' => $hasLocation,
            'activeFilters' => $this->hasActiveFilters(),
        ]);
    }
}
