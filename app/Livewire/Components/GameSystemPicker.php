<?php

namespace App\Livewire\Components;

use App\Dto\GameSystemOption;
use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reusable game system picker with debounced search.
 *
 * Usage in parent Blade:
 *   <livewire:components.game-system-picker
 *       wire:model="game_system_id"
 *       :fieldId="'game-system'"
 *       :label="__('games.content_game_system')"
 *       :error="$errors->first('game_system_id')"
 *   />
 *
 * Features:
 *   - Debounced search (300ms)
 *   - User's favorite game systems shown when focused with empty/minimal search
 *   - Base games only in main results, with expansion count ("+ N expansions")
 *   - When selecting a base game that has expansions, a sub-picker appears
 *     allowing the user to choose the base game or a specific expansion
 *   - Expansions sorted by BGG rank (popularity) then rating
 */
class GameSystemPicker extends Component
{
    use EscapesLikeWildcards;
    use QueriesTranslatableColumns;

    #[Locked]
    public string $fieldId = 'game-system';

    #[Locked]
    public string $label;

    #[Locked]
    public string $gameType = 'boardgame';

    public ?string $value = null;

    public string $error = '';

    public string $search = '';

    public bool $isOpen = false;

    public bool $showExpansionPicker = false;

    /** @var string|null The base game ID when a base-with-expansions is selected */
    public ?string $selectedBaseId = null;

    public function mount(
        string $fieldId = 'game-system',
        string $label = '',
        ?string $value = null,
        string $error = '',
        ?string $gameType = null,
    ): void {
        $this->fieldId = $fieldId;
        $this->label = $label ?: __('games.content_game_system');
        $this->value = $value;
        $this->error = $error;
        $this->gameType = $gameType ?? 'boardgame';

        // Map GameType enum values to BGG database convention
        $this->gameType = match ($this->gameType) {
            'board_game' => 'boardgame',
            default => $this->gameType, // 'ttrpg' and others pass through
        };

        // Pre-populate search text if editing an existing selection
        if ($value) {
            $system = GameSystem::find($value);
            if ($system) {
                $this->search = $system->name;
            }
        }
    }

    public function updatedValue(): void
    {
        // Sync from parent via wire:model
        $this->dispatch('value-updated', value: $this->value);
    }

    /**
     * @return Collection<int, GameSystem>
     */
    #[Computed]
    public function favoriteSystems(): Collection
    {
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        return $user->favoriteGameSystems()
            ->where('type', $this->gameType)
            ->whereNull('base_game_id')
            ->orderBy('name')
            ->get();
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

        // Filter by type column (boardgame / ttrpg)
        $query = GameSystem::where('type', $this->gameType);

        // Only show base games (not expansions/sub-items)
        if ($this->gameType === 'boardgame') {
            $query->whereNull('base_game_id')
                ->where('bgg_type', '!=', 'boardgameexpansion');
        } else {
            $query->whereNull('base_game_id');
        }

        // Translatable search uses its own escaping; keep $escapedTerm for
        // the raw SQL prefix-match sort which needs pre-escaped LIKE input.
        $escapedTerm = $this->escapeLikeWildcards($term);

        // Match on name directly, or match expansions whose name contains the term
        // (expansion search only for board games)
        $query->where(function ($q) use ($term) {
            $this->whereTranslatableLike($q, 'name', $term);
            if ($this->gameType === 'boardgame') {
                $q->orWhereHas('expansions', function ($q) use ($term) {
                    $this->whereTranslatableLike($q, 'name', $term);
                });
            }
        });

        // Sort: prefix matches first, then by BGG rank, then by rating.
        // PostgreSQL-specific: name->>? uses JSONB extraction operator.
        $locale = app()->getLocale();
        $query->orderByRaw('CASE WHEN name->>? ILIKE ? THEN 0 ELSE 1 END', [$locale, "{$escapedTerm}%"]);

        $query = $query
            ->orderBy('bgg_rank', 'asc')
            ->orderBy('bgg_average_rating', 'desc')
            ->orderBy('name')
            ->limit(20);

        // Only load expansion count for board games
        if ($this->gameType === 'boardgame') {
            $query->withCount('expansions');
        }

        return $query->get();
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

    public function setOpen(): void
    {
        $this->isOpen = true;
    }

    public function updatedSearch(): void
    {
        $this->isOpen = true;
        $this->showExpansionPicker = false;
        $this->selectedBaseId = null;

        // If the user clears the search, clear the selection
        if (trim($this->search) === '') {
            $this->selectSystem(null);
        }
    }

    public function selectSystem(?string $id): void
    {
        $this->value = $id;

        if ($id === null) {
            $this->search = '';
            $this->isOpen = false;
            $this->showExpansionPicker = false;
            $this->selectedBaseId = null;
            $this->dispatch('value-updated', value: null);

            return;
        }

        $system = GameSystem::find($id);
        if (! $system) {
            return;
        }

        $this->search = $system->name;
        $this->isOpen = false;
        $this->dispatch('value-updated', value: $id);
    }

    /**
     * User selected a base game from the search results.
     * If it has expansions, show the expansion picker.
     * If no expansions, select it directly.
     */
    public function pickFromSearch(string $id): void
    {
        $system = GameSystem::find($id);
        if (! $system) {
            return;
        }

        // Skip expansion picker for non-boardgame types
        if ($this->gameType === 'boardgame') {
            $system = GameSystem::withCount('expansions')->find($id);
            if (! $system) {
                return;
            }

            $expansionCount = $system->expansions_count ?? $system->expansions()->count();

            if ($expansionCount > 0) {
                // Show expansion sub-picker
                $this->selectedBaseId = $id;
                $this->showExpansionPicker = true;
                $this->isOpen = false;

                // Pre-select the base game
                $this->selectSystem($id);

                return;
            }
        }

        $this->selectSystem($id);
    }

    /**
     * Select a specific item (base or expansion) from the expansion picker.
     */
    public function pickExpansion(string $id): void
    {
        $this->selectSystem($id);
        $this->showExpansionPicker = false;
    }

    public function pickFavorite(string $id): void
    {
        $this->pickFromSearch($id);
    }

    public function clearSelection(): void
    {
        $this->selectSystem(null);
    }

    public function closeDropdown(): void
    {
        $this->isOpen = false;

        // If search text doesn't match the selected system, clear it
        if ($this->value) {
            $system = GameSystem::find($this->value);
            if ($system && $this->search !== $system->name) {
                // User edited the search away from the selection — treat as clear
                $this->selectSystem(null);
            }
        } elseif (trim($this->search) !== '') {
            $this->search = '';
        }
    }

    public function render(): View
    {
        return view('livewire.components.game-system-picker');
    }
}
