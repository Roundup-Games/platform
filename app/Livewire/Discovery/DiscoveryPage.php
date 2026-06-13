<?php

namespace App\Livewire\Discovery;

use App\Dto\DiscoveryFilters;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\SafetyTool;
use App\Enums\VibeFlag;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class DiscoveryPage extends Component
{
    use HasGuestLocation;
    use ManagesDiscoveryFilters;

    // Mode
    #[Url]
    public string $mode = 'all';

    // Load more
    public int $displayCount = 12;

    // Page-specific filters
    /** @var array<int, int|string> */
    #[Url]
    public array $safety_tools = [];

    /** @var array<int, int|string> */
    #[Url]
    public array $category_ids = [];

    /** @var array<int, int|string> */
    #[Url]
    public array $mechanic_ids = [];

    // Entity-specific filters
    #[Url]
    public string $date = '';

    #[Url]
    public string $recurrence = '';

    // Updating hooks

    public function updating(): void
    {
        $this->displayCount = 12;
    }

    public function loadMore(): void
    {
        $this->displayCount += 12;
    }

    // Actions

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->displayCount = 12;
    }

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->displayCount = 12;
    }

    public function setRecurrence(string $recurrence): void
    {
        $this->recurrence = $recurrence;
        $this->displayCount = 12;
    }

    public function toggleSafetyTool(string $tool): void
    {
        $this->safety_tools = $this->toggleArrayValue($this->safety_tools, $tool);
        $this->displayCount = 12;
    }

    public function toggleCategory(string $categoryId): void
    {
        $this->category_ids = $this->toggleArrayValue($this->category_ids, $categoryId);
        $this->displayCount = 12;
    }

    public function toggleMechanic(string $mechanicId): void
    {
        $this->mechanic_ids = $this->toggleArrayValue($this->mechanic_ids, $mechanicId);
        $this->displayCount = 12;
    }

    /**
     * @param  array<int, int|string>  $array
     * @return array<int, int|string>
     */
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
        $this->displayCount = 12;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || ! empty($this->vibe_flags)
            || ! empty($this->safety_tools)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || $this->recurrence
            || ! empty($this->category_ids)
            || ! empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // Render

    public function render(): View
    {
        $title = match ($this->mode) {
            'games' => __('discovery.seo_title_browse_games'),
            'campaigns' => __('discovery.seo_title_browse_campaigns'),
            default => __('discovery.seo_title_browse_all'),
        };

        seo(new SEOData(
            title: $title,
            description: __('discovery.seo_description_browse'),
        ));

        $service = app(DiscoveryQueryService::class);
        $user = Auth::user();
        $filters = DiscoveryFilters::fromLivewire($this);
        $hasLocation = $this->hasGuestLocation();
        $lat = $this->guestLat ?? null;
        $lng = $this->guestLng ?? null;

        // Fallback to the logged-in user's saved location when browser geolocation is unavailable
        if (! $hasLocation && $user?->location_id) {
            $userLocation = Location::find($user->location_id);
            if ($userLocation) {
                $lat = (float) $userLocation->latitude;
                $lng = (float) $userLocation->longitude;
                $hasLocation = true;
            }
        }

        $results = match ($this->mode) {
            'games' => $service->getGamesResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->date, $this->displayCount),
            'campaigns' => $service->getCampaignsResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->recurrence, $this->displayCount),
            default => tap(
                $service->getMergedResults($filters, $user, $this->radius, $lat, $lng, $hasLocation, $this->date, $this->recurrence, $this->displayCount),
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
