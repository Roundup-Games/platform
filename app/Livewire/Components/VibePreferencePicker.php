<?php

namespace App\Livewire\Components;

use App\Enums\VibeFlag;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reusable vibe preference picker with segmented controls and tri-state chips.
 *
 * Renders 6 paired vibe flags as segmented controls (favorite-A / neutral / favorite-B)
 * and 8 standalone flags as tri-state chips (neutral → favorite → avoid).
 *
 * Modes:
 *   'preference' — Full tri-state for user profiles (favorite/avoid/neutral).
 *   'selection'  — Same UX for game/campaign creation. Favorites stored as selected
 *                  flags; the parent receives the full preferences map via event and
 *                  can extract favorites as a flat array for DB storage.
 *
 * Usage in parent Blade:
 *   <livewire:components.vibe-preference-picker
 *       :preferences="$existingPreferences"
 *       mode="selection"
 *   />
 *
 * Dispatches:
 *   vibe-preferences-changed — { preferences: array<string, string|null> }
 */
class VibePreferencePicker extends Component
{
    /** @var array<string, string|null> Map of VibeFlag value → null|'favorite'|'avoid' */
    public array $preferences = [];

    #[Locked]
    public string $mode = 'preference';

    public function mount(array $preferences = [], string $mode = 'preference'): void
    {
        $this->mode = $mode;

        // Initialize all flags to neutral (null)
        foreach (VibeFlag::cases() as $flag) {
            $this->preferences[$flag->value] = $preferences[$flag->value] ?? null;
        }
    }

    /**
     * Set a paired flag to a value, with partner auto-avoided when favoriting.
     * Clicking neutral (null) clears both flags.
     */
    public function togglePaired(string $flag, string $partnerFlag, ?string $value): void
    {
        if ($value === null) {
            // Neutral clicked — clear both
            $this->preferences[$flag] = null;
            $this->preferences[$partnerFlag] = null;
        } elseif ($value === 'favorite') {
            // Favorite the clicked flag, auto-avoid the partner
            $this->preferences[$flag] = 'favorite';
            $this->preferences[$partnerFlag] = 'avoid';
        } elseif ($value === 'avoid') {
            // Avoid the clicked flag, leave partner as-is (asymmetric exclusivity)
            $this->preferences[$flag] = 'avoid';
        }

        $this->dispatch('vibe-preferences-changed', preferences: $this->preferences);
    }

    /**
     * Cycle a standalone flag: neutral → favorite → avoid → neutral.
     */
    public function toggleStandalone(string $flag): void
    {
        $current = $this->preferences[$flag] ?? null;

        $this->preferences[$flag] = match ($current) {
            null => 'favorite',
            'favorite' => 'avoid',
            'avoid' => null,
            default => null,
        };

        $this->dispatch('vibe-preferences-changed', preferences: $this->preferences);
    }

    /**
     * Get paired flags as structured pairs with labels and current state.
     *
     * @return array<int, array{flagA: string, labelA: string, flagB: string, labelB: string, valueA: string|null, valueB: string|null}>
     */
    #[Computed]
    public function getPairedFlags(): array
    {
        $pairs = [];

        foreach (VibeFlag::mutuallyExclusivePairs() as $pair) {
            $pairs[] = [
                'flagA' => $pair[0]->value,
                'labelA' => $pair[0]->label(),
                'flagB' => $pair[1]->value,
                'labelB' => $pair[1]->label(),
                'valueA' => $this->preferences[$pair[0]->value] ?? null,
                'valueB' => $this->preferences[$pair[1]->value] ?? null,
            ];
        }

        return $pairs;
    }

    /**
     * Get standalone flags (those not in any mutually exclusive pair).
     *
     * @return array<int, array{flag: string, label: string, value: string|null, group: string}>
     */
    #[Computed]
    public function getStandaloneFlags(): array
    {
        $pairedValues = [];
        foreach (VibeFlag::mutuallyExclusivePairs() as $pair) {
            $pairedValues[$pair[0]->value] = true;
            $pairedValues[$pair[1]->value] = true;
        }

        $grouped = VibeFlag::grouped();
        $standalone = [];

        foreach ($grouped as $groupKey => $group) {
            foreach ($group['options'] as $flagValue => $flagLabel) {
                if (! isset($pairedValues[$flagValue])) {
                    $standalone[] = [
                        'flag' => $flagValue,
                        'label' => $flagLabel,
                        'value' => $this->preferences[$flagValue] ?? null,
                        'group' => $groupKey,
                        'groupLabel' => $group['label'],
                    ];
                }
            }
        }

        return $standalone;
    }

    /**
     * Extract favorite flag values as a flat array (for DB storage).
     *
     * @return string[]
     */
    public function getSelectedFlags(): array
    {
        return collect($this->preferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.components.vibe-preference-picker');
    }
}
