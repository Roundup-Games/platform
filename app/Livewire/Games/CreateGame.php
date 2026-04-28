<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameType;
use App\Enums\VibeFlag;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateGame extends Component
{
    public string $name = '';

    public string $game_type = 'board_game';

    public ?int $game_system_id = null;

    public string $date_time = '';

    public string $description = '';

    public ?string $expected_duration = '';

    public ?string $price = '';

    public string $language = 'en';

    public ?int $location_id = null;

    public string $visibility = 'protected';

    public array $minimum_requirements = [];

    public array $safety_rules = [];

    public ?int $min_players = null;

    public ?int $max_players = null;

    public ?string $experience_level = null;

    public ?string $complexity = null;

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', from VibePreferencePicker */
    public array $vibePreferences = [];

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'game_type' => 'required|string|in:' . implode(',', GameType::values()),
            'game_system_id' => 'nullable|exists:game_systems,id',
            'date_time' => 'required|date',
            'description' => 'nullable|string|max:5000',
            'expected_duration' => 'nullable|numeric|min:0.5|max:24',
            'price' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:' . implode(',', ContentLanguage::values()),
            'location_id' => 'nullable|integer|exists:locations,id',
            'visibility' => 'required|in:public,protected,private',
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'required|integer|min:2|max:30',
            'experience_level' => 'nullable|string|in:' . implode(',', ExperienceLevel::values()),
            'complexity' => 'nullable|numeric|min:1|max:5',
        ];
    }

    // ── Event Listeners ──────────────────────────────────

    #[On('location-selected')]
    public function onLocationSelected(int $locationId, string $city, ?string $address = null): void
    {
        $this->location_id = $locationId;
    }

    #[On('location-removed')]
    public function onLocationRemoved(): void
    {
        $this->location_id = null;
    }

    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    #[On('safety-tools-changed')]
    public function onSafetyToolsChanged(array $safetyRules): void
    {
        $this->safety_rules = $safetyRules;
    }

    #[On('value-updated')]
    public function onGameSystemPicked($value): void
    {
        $id = is_numeric($value) ? (int) $value : null;
        $this->game_system_id = $id;
        $this->autofillFromGameSystem($id);
    }

    // ── Lifecycle Hooks ──────────────────────────────────

    public function updatedGameSystemId(?int $id): void
    {
        $this->autofillFromGameSystem($id);
    }

    public function updatedExpectedDuration(): void
    {
        if ($this->expected_duration === '' || $this->expected_duration === null) {
            return;
        }

        $value = (float) $this->expected_duration;
        $rounded = round($value * 2) / 2;
        $this->expected_duration = (string) max($rounded, 0.5);
    }

    public function updatedMinPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    public function updatedMaxPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    // ── Computed ─────────────────────────────────────────

    #[Computed]
    public function languageOptions(): array
    {
        $options = [];
        foreach (ContentLanguage::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    #[Computed]
    public function gameTypeOptions(): array
    {
        $options = [];
        foreach (GameType::cases() as $case) {
            $options[$case->value] = __('games.type_' . $case->value);
        }

        return $options;
    }

    #[Computed]
    public function experienceLevelOptions(): array
    {
        $options = ['' => __('discovery.content_any')];
        foreach (ExperienceLevel::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    #[Computed]
    public function canCreatePublic(): bool
    {
        $user = Auth::user();

        return $user && $user->can_create_public_entries;
    }

    // ── Actions ──────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('create', Game::class);

        // Gate public visibility
        if ($this->visibility === 'public' && ! $this->canCreatePublic) {
            $this->visibility = 'private';
        }

        $validated = $this->validate();

        // Cross-field validation after individual field validation
        if (
            isset($validated['min_players'], $validated['max_players'])
            && $validated['min_players'] > $validated['max_players']
        ) {
            $this->addError('min_players', __('games.error_min_players_cannot_exceed_max_players'));

            return;
        }

        // Extract favorite vibe flags for storage
        $vibeFlags = $this->selectedVibeFlags();

        $game = Game::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'name' => $validated['name'],
            'game_type' => $validated['game_type'],
            'date_time' => $validated['date_time'],
            'description' => $validated['description'],
            'expected_duration' => $validated['expected_duration'] ?: 2,
            'price' => $validated['price'] ?: 0,
            'language' => $validated['language'],
            'location_id' => $this->location_id,
            'location' => ['details' => ''],
            'status' => 'scheduled',
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] ?: null,
            'safety_rules' => $validated['safety_rules'] ?: null,
            'min_players' => $validated['min_players'] ?? 2,
            'max_players' => $validated['max_players'] ?? 6,
            'experience_level' => $validated['experience_level'],
            'complexity' => $validated['complexity'] ?: null,
            'vibe_flags' => ! empty($vibeFlags) ? $vibeFlags : null,
        ]);

        Log::info('Game created', [
            'game_id' => $game->id,
            'name' => $game->name,
            'owner_id' => Auth::id(),
        ]);

        session()->flash('success', __('games.flash_game_name_created_successfully', ['name' => $game->name]));

        $this->redirect(route('games.detail', $game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.games.create-game');
    }

    // ── Private Helpers ──────────────────────────────────

    protected function autofillFromGameSystem(?int $id): void
    {
        if ($id === null) {
            return;
        }

        $system = GameSystem::find($id);
        if ($system === null) {
            return;
        }

        if ($system->average_play_time && $this->expected_duration === '') {
            $hours = $system->average_play_time / 60;
            $rounded = round($hours * 2) / 2;
            $this->expected_duration = (string) max($rounded, 0.5);
        }

        if ($system->min_players && $this->min_players === null) {
            $this->min_players = $system->min_players;
        }
        if ($system->max_players && $this->max_players === null) {
            $this->max_players = $system->max_players;
        }

        if ($system->bgg_average_weight && $this->complexity === null) {
            $this->complexity = (string) round((float) $system->bgg_average_weight, 2);
        }

        if ($this->experience_level === null && $system->bgg_average_weight) {
            $weight = (float) $system->bgg_average_weight;
            if ($weight <= 2.0) {
                $this->experience_level = 'beginner';
            } elseif ($weight <= 3.5) {
                $this->experience_level = 'intermediate';
            } else {
                $this->experience_level = 'advanced';
            }
        }
    }

    protected function validatePlayerCounts(): void
    {
        if (
            $this->min_players !== null
            && $this->max_players !== null
            && $this->min_players > $this->max_players
        ) {
            $this->addError('min_players', __('games.error_min_players_cannot_exceed_max_players'));
        }
    }

    /**
     * Extract favorite flags from the picker as a flat array for DB storage.
     * Validates against the VibeFlag enum to prevent tampering.
     *
     * @return string[]
     */
    protected function selectedVibeFlags(): array
    {
        $validValues = VibeFlag::values();

        return collect($this->vibePreferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->filter(fn ($key) => in_array($key, $validValues))
            ->values()
            ->all();
    }
}
