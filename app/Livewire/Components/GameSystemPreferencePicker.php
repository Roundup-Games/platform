<?php

namespace App\Livewire\Components;

use App\Dto\GameSystemOption;
use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Multi-select game system preference picker with debounced search, expansion
 * sub-picker, and conflict detection.
 *
 * Two-step flow (mirrors GameSystemPicker):
 *   1. Search shows base games with expansion counts
 *   2. Selecting a base game with expansions opens a sub-picker listing the
 *      base game first, then its expansions in BGG rank order
 *   3. Selecting the base adds it directly (implies all expansions)
 *   4. Selecting a specific expansion adds it individually
 *   5. Base games can also be added directly if they have no expansions
 *
 * Resolution rules (enforced at data layer, UI shows advisory warnings):
 *   - Favorite base → implies favorite all expansions
 *   - Avoid base → implies avoid all expansions
 *   - Avoid specific expansion under a favorite base: allowed (avoid wins)
 *   - Favorite specific expansion under an avoided base: blocked at UI level
 */
class GameSystemPreferencePicker extends Component
{
    use EscapesLikeWildcards;
    use QueriesTranslatableColumns;

    #[Locked]
    public string $preferenceType = 'favorite';

    /** @var array<int, string> IDs of currently selected game systems */
    public array $selectedIds = [];

    /** @var array<int, string> IDs from the *other* preference type, used for conflict detection */
    #[Locked]
    public array $conflictIds = [];

    public string $search = '';

    public bool $isOpen = false;

    public bool $showExpansionPicker = false;

    /** @var string|null The base game ID when expansion sub-picker is shown */
    public ?string $selectedBaseId = null;

    public string $conflictMessage = '';

    /**
     * @param  array<int, string>  $selectedIds
     * @param  array<int, string>  $conflictIds
     */
    public function mount(
        string $preferenceType = 'favorite',
        array $selectedIds = [],
        array $conflictIds = [],
    ): void {
        $this->preferenceType = $preferenceType;
        $this->selectedIds = array_map('strval', $selectedIds);
        $this->conflictIds = array_map('strval', $conflictIds);
    }

    /**
     * @return Collection<int, GameSystem>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $term = trim($this->search);

        // Only search base games (not expansions/sub-items)
        $query = GameSystem::where(function ($q) {
            $q->whereNull('base_game_id');
        });

        // Translatable search uses its own escaping; keep $escapedTerm for
        // the raw SQL prefix-match sort which needs pre-escaped LIKE input.
        $escapedTerm = $this->escapeLikeWildcards($term);

        $query->where(function ($q) use ($term) {
            $this->whereTranslatableLike($q, 'name', $term);
        });

        // Sort: prefix matches first, then by BGG rank, then by rating.
        // PostgreSQL-specific: name->>? uses JSONB extraction operator.
        $locale = app()->getLocale();
        $query->orderByRaw('CASE WHEN name->>? ILIKE ? THEN 0 ELSE 1 END', [$locale, "{$escapedTerm}%"]);

        return $query
            ->withCount('expansions')
            ->orderBy('bgg_rank', 'asc')
            ->orderBy('bgg_average_rating', 'desc')
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, GameSystem>
     */
    #[Computed]
    public function selectedSystems(): Collection
    {
        if (empty($this->selectedIds)) {
            return collect();
        }

        return GameSystem::with(['baseGame', 'expansions'])
            ->withCount('expansions')
            ->whereIn('id', $this->selectedIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, GameSystemOption>
     */
    #[Computed]
    public function expansionOptions(): Collection
    {
        if (! $this->selectedBaseId) {
            return collect();
        }

        $base = GameSystem::find($this->selectedBaseId);
        if (! $base) {
            return collect();
        }

        // Base game first, then expansions sorted by popularity
        $baseItem = collect([
            new GameSystemOption(
                id: $base->id,
                name: $base->name,
                is_base: true,
                bgg_rank: $base->bgg_rank,
                bgg_average_rating: $base->bgg_average_rating,
                thumbnail_url: $base->thumbnail_url,
            ),
        ]);

        $expansionsQuery = $base->expansions()
            ->orderBy('bgg_rank', 'asc')
            ->orderBy('bgg_average_rating', 'desc')
            ->orderBy('name')
            ->get();
        /** @var Collection<int, GameSystem> $expansionsQuery */
        $expansions = $expansionsQuery->map(fn (GameSystem $exp) => new GameSystemOption(
            id: $exp->id,
            name: $exp->name,
            is_base: false,
            bgg_rank: $exp->bgg_rank,
            bgg_average_rating: $exp->bgg_average_rating,
            thumbnail_url: $exp->thumbnail_url,
        ));

        return $baseItem->merge($expansions);
    }

    /**
     * User selected a base game from the search results.
     * If it has expansions, show the expansion sub-picker.
     * If no expansions, add it directly.
     */
    public function pickFromSearch(string $id): void
    {
        $system = GameSystem::withCount('expansions')->find($id);
        if (! $system) {
            return;
        }

        if ($system->expansions_count > 0) {
            // Show expansion sub-picker
            $this->selectedBaseId = $id;
            $this->showExpansionPicker = true;
            $this->isOpen = false;
        } else {
            $this->add($id);
        }
    }

    /**
     * Select a specific item (base or expansion) from the expansion sub-picker.
     */
    public function pickExpansion(string $id): void
    {
        $this->add($id);
        $this->showExpansionPicker = false;
        $this->selectedBaseId = null;
    }

    public function add(string $id): void
    {
        // Prevent duplicates
        if (in_array($id, $this->selectedIds, true)) {
            $this->search = '';
            $this->isOpen = false;

            return;
        }

        // Block: favoriting an expansion when its base is avoided
        if ($this->preferenceType === 'favorite') {
            $system = GameSystem::findOrFail($id);
            if ($system->base_game_id && in_array($system->base_game_id, $this->conflictIds, true)) {
                $rawBaseName = GameSystem::where('id', $system->base_game_id)->value('name');
                $baseName = is_string($rawBaseName) ? $rawBaseName : 'its base game';
                $this->conflictMessage = __('games.error_name_s_base_game_base_name_is_in_your_avoid_list', [
                    'name' => $system->name,
                    'baseName' => $baseName,
                ]);

                return;
            }
        }

        $this->checkConflict($id);

        $this->selectedIds[] = $id;
        $this->search = '';
        $this->isOpen = false;
        $this->showExpansionPicker = false;
        $this->selectedBaseId = null;

        $this->dispatch('selection-changed',
            preferenceType: $this->preferenceType,
            selectedIds: $this->selectedIds,
        );
    }

    public function remove(string $id): void
    {
        $this->selectedIds = array_values(
            array_filter($this->selectedIds, fn ($sid) => $sid !== $id),
        );

        $this->conflictMessage = '';

        $this->dispatch('selection-changed',
            preferenceType: $this->preferenceType,
            selectedIds: $this->selectedIds,
        );
    }

    public function cancelExpansionPicker(): void
    {
        $this->showExpansionPicker = false;
        $this->selectedBaseId = null;
    }

    public function checkConflict(string $id): void
    {
        $system = GameSystem::find($id);

        if ($this->preferenceType === 'avoid') {
            // Adding to avoid: warn if the system (or its base) is in favorites (conflictIds)
            if (in_array($id, $this->conflictIds, true)) {
                $name = $system->name ?? 'This game';
                $this->conflictMessage = __('games.error_name_is_in_your_favorites_the_avoid_preference', ['name' => $name]);

                return;
            }

            // Check if the system's base game is in conflictIds
            if ($system && $system->base_game_id && in_array($system->base_game_id, $this->conflictIds, true)) {
                $rawBaseName2 = GameSystem::where('id', $system->base_game_id)->value('name');
                $baseName = is_string($rawBaseName2) ? $rawBaseName2 : 'its base game';
                $this->conflictMessage = __('games.error_name_s_base_game_base_name_is_in_your_favorites', [
                    'name' => $system->name,
                    'baseName' => $baseName,
                ]);

                return;
            }
        } elseif ($this->preferenceType === 'favorite') {
            // Adding to favorite: warn if the system is in avoid list (conflictIds)
            if (in_array($id, $this->conflictIds, true)) {
                $name = $system->name ?? 'This game';
                $this->conflictMessage = __('games.error_name_is_in_your_avoid_list_the_avoid_preference', ['name' => $name]);

                return;
            }
        }

        $this->conflictMessage = '';
    }

    public function setOpen(): void
    {
        $this->isOpen = true;
    }

    public function closeDropdown(): void
    {
        $this->isOpen = false;
    }

    public function updatedSearch(): void
    {
        $this->isOpen = true;
        $this->conflictMessage = '';
        $this->showExpansionPicker = false;
        $this->selectedBaseId = null;
    }

    public function render(): View
    {
        return view('livewire.components.game-system-preference-picker');
    }
}
