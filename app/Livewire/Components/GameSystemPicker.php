<?php

namespace App\Livewire\Components;

use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
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
    #[Locked]
    public string $fieldId = 'game-system';

    #[Locked]
    public string $label;

    public ?int $value = null;

    public string $error = '';

    public string $search = '';

    public bool $isOpen = false;

    public bool $showExpansionPicker = false;

    /** @var int|null The base game ID when a base-with-expansions is selected */
    public ?int $selectedBaseId = null;

    public function mount(
        string $fieldId = 'game-system',
        string $label = '',
        ?int $value = null,
        string $error = '',
    ): void {
        $this->fieldId = $fieldId;
        $this->label = $label ?: __('games.content_game_system');
        $this->value = $value;
        $this->error = $error;

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

    #[Computed]
    public function favoriteSystems()
    {
        $user = Auth::user();
        if (! $user) {
            return collect();
        }

        return $user->favoriteGameSystems()
            ->where(function ($q) {
                $q->where('bgg_type', 'boardgame')
                    ->orWhereNull('bgg_type');
            })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function searchResults()
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $term = trim($this->search);
        $driver = GameSystem::query()->getQuery()->getConnection()->getDriverName();

        // Only search base games (boardgame or null bgg_type)
        $query = GameSystem::where(function ($q) {
            $q->where('bgg_type', 'boardgame')
                ->orWhereNull('bgg_type');
        });

        $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';
        $escapedTerm = $this->escapeLikeWildcards($term);

        // Match on name directly, or match expansions whose name contains the term
        $query->where(function ($q) use ($likeOperator, $escapedTerm) {
            $q->where('name', $likeOperator, "%{$escapedTerm}%")
                ->orWhereHas('expansions', function ($q) use ($likeOperator, $escapedTerm) {
                    $q->where('name', $likeOperator, "%{$escapedTerm}%");
                });
        });

        // Sort: prefix matches first, then by BGG rank, then by rating
        if ($driver === 'pgsql') {
            $query->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', ["{$escapedTerm}%"]);
        } else {
            $query->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$escapedTerm}%"]);
        }

        return $query
            ->withCount('expansions')
            ->orderBy('bgg_rank', 'asc')         // popularity: lower rank = more popular
            ->orderBy('bgg_average_rating', 'desc')
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function expansionOptions()
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
            (object) [
                'id' => $base->id,
                'name' => $base->name,
                'is_base' => true,
                'bgg_rank' => $base->bgg_rank,
                'bgg_average_rating' => $base->bgg_average_rating,
                'thumbnail_url' => $base->thumbnail_url,
            ],
        ]);

        $expansions = $base->expansions()
            ->orderBy('bgg_rank', 'asc')
            ->orderBy('bgg_average_rating', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (GameSystem $exp) => (object) [
                'id' => $exp->id,
                'name' => $exp->name,
                'is_base' => false,
                'bgg_rank' => $exp->bgg_rank,
                'bgg_average_rating' => $exp->bgg_average_rating,
                'thumbnail_url' => $exp->thumbnail_url,
            ]);

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

    public function selectSystem(?int $id): void
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
    public function pickFromSearch(int $id): void
    {
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
        } else {
            $this->selectSystem($id);
        }
    }

    /**
     * Select a specific item (base or expansion) from the expansion picker.
     */
    public function pickExpansion(int $id): void
    {
        $this->selectSystem($id);
        $this->showExpansionPicker = false;
    }

    public function pickFavorite(int $id): void
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

    public function render()
    {
        return view('livewire.components.game-system-picker');
    }
}
