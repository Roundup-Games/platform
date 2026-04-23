<?php

namespace App\Livewire\Discovery;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Traits\EscapesLikeWildcards;
use App\Traits\HasGuestLocation;
use App\Traits\DiscoveryUtilities;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class BoardGamesDiscovery extends Component
{
    use DiscoveryUtilities;
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    // ── Shared filters ─────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', for VibePreferencePicker */
    public array $vibePreferences = [];

    /** @var array<string> Safety tools — kept empty for trait compatibility; not exposed in board game UI */
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

    // ── Proximity filter ───────────────────────────────

    /** @var float Search radius in km (0 = no proximity filter) */
    #[Url(as: 'radius')]
    public float $radius = 0;

    /** @var bool Whether results came from the wider fallback radius */
    public bool $usingFallbackRadius = false;

    // ── Games-specific filters ─────────────────────────

    #[Url]
    public string $date = '';

    // ── Lifecycle ──────────────────────────────────────

    public function mount(): void
    {
        $user = Auth::user();
        if ($user && $user->preferred_language && !$this->language) {
            $this->language = $user->preferred_language->value;
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

    // ── Updating hooks ─────────────────────────────────

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGameSystemId(): void
    {
        $this->resetPage();
    }

    public function updatingExperienceLevel(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryIds(): void
    {
        $this->resetPage();
    }

    public function updatingMechanicIds(): void
    {
        $this->resetPage();
    }

    public function updatingRadius(): void
    {
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────────────

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->resetPage();
    }

    public function setRadius(float $radius): void
    {
        if ($radius != 0 && !in_array($radius, self::RADIUS_OPTIONS, false)) {
            return;
        }
        $this->radius = $radius;
        $this->usingFallbackRadius = false;
        $this->resetPage();
    }

    public function toggleCategory(int $categoryId): void
    {
        $index = array_search($categoryId, $this->category_ids, true);
        if ($index !== false) {
            unset($this->category_ids[$index]);
            $this->category_ids = array_values($this->category_ids);
        } else {
            $this->category_ids[] = $categoryId;
        }
        $this->resetPage();
    }

    public function toggleMechanic(int $mechanicId): void
    {
        $index = array_search($mechanicId, $this->mechanic_ids, true);
        if ($index !== false) {
            unset($this->mechanic_ids[$index]);
            $this->mechanic_ids = array_values($this->mechanic_ids);
        } else {
            $this->mechanic_ids[] = $mechanicId;
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'category_ids', 'mechanic_ids', 'radius',
        ]);
        $this->usingFallbackRadius = false;
        // Reset vibe preferences to neutral
        foreach (VibeFlag::cases() as $flag) {
            $this->vibePreferences[$flag->value] = null;
        }
        $this->resetPage();
    }

    // ── Picker event listeners ─────────────────────────

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
            || $this->language
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || !empty($this->category_ids)
            || !empty($this->mechanic_ids)
            || $this->radius > 0;
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $results = $this->getBoardGameResults();

        // Cross-track hint: count active public TTRPG campaigns
        $adventureCount = \App\Models\Campaign::where('status', 'active')
            ->where('visibility', 'public')
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'ttrpg'))
            ->count();

        return view('livewire.discovery.board-games-discovery', [
            'results' => $results,
            'recommendations' => $this->getRecommendations('boardgame'),
            'experienceLevels' => ExperienceLevel::cases(),
            'languages' => ContentLanguage::cases(),
            'curatedCategories' => $this->getCuratedCategories(),
            'curatedMechanics' => $this->getCuratedMechanics(),
            'radiusOptions' => self::RADIUS_OPTIONS,
            'hasLocation' => $this->hasGuestLocation(),
            'adventureCount' => $adventureCount,
        ]);
    }

    /**
     * Build and paginate board-game-scoped results.
     *
     * Adds type=boardgame constraint so only board games appear
     * on the board games discovery page (no TTRPG bleed-through).
     */
    protected function getBoardGameResults()
    {
        $query = $this->buildGamesQuery()
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'boardgame'));

        $paginator = $query->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game'));

        if ($this->radius > 0 && $this->hasGuestLocation()) {
            $this->enrichWithDistance($paginator->getCollection(), 'game');
        }

        return $paginator;
    }
}
