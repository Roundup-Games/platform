<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateGame extends Component
{
    public string $name = '';

    public ?int $game_system_id = null;

    public string $date_time = '';

    public string $description = '';

    public ?string $expected_duration = '';

    public ?string $price = '';

    public string $language = 'en';

    public string $location_details = '';

    public string $visibility = 'public';

    public array $minimum_requirements = [];

    public array $safety_rules = [];

    public ?int $min_players = null;

    public ?int $max_players = null;

    public ?string $experience_level = null;

    public ?string $complexity = null;

    /** @var string[] */
    public array $vibe_flags = [];

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'game_system_id' => 'nullable|exists:game_systems,id',
            'date_time' => 'required|date',
            'description' => 'nullable|string|max:5000',
            'expected_duration' => 'nullable|numeric|min:0.5|max:24',
            'price' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:' . implode(',', ContentLanguage::values()),
            'location_details' => 'nullable|string|max:1000',
            'visibility' => 'required|in:public,protected,private',
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'nullable|integer|min:1|max:99',
            'experience_level' => 'nullable|string|in:' . implode(',', ExperienceLevel::values()),
            'complexity' => 'nullable|numeric|min:1|max:5',
            'vibe_flags' => 'nullable|array',
            'vibe_flags.*' => 'string|in:' . implode(',', VibeFlag::values()),
        ];
    }

    /**
     * When the user picks a game system, auto-fill duration, player counts,
     * complexity, and experience level from the game system data.
     */
    public function updatedGameSystemId(?int $id): void
    {
        if ($id === null) {
            return;
        }

        $system = GameSystem::find($id);
        if ($system === null) {
            return;
        }

        // Duration: convert average_play_time (minutes) to hours, round to nearest 0.5
        if ($system->average_play_time && $this->expected_duration === '') {
            $hours = $system->average_play_time / 60;
            $rounded = round($hours * 2) / 2;
            $this->expected_duration = (string) max($rounded, 0.5);
        }

        // Player counts from game system
        if ($system->min_players && $this->min_players === null) {
            $this->min_players = $system->min_players;
        }
        if ($system->max_players && $this->max_players === null) {
            $this->max_players = $system->max_players;
        }

        // Complexity from BGG weight (1-5 scale)
        if ($system->bgg_average_weight && $this->complexity === null) {
            $this->complexity = (string) round((float) $system->bgg_average_weight, 2);
        }
    }

    /**
     * Round duration to nearest 0.5 when the user leaves the field.
     */
    public function updatedExpectedDuration(): void
    {
        if ($this->expected_duration === '' || $this->expected_duration === null) {
            return;
        }

        $value = (float) $this->expected_duration;
        $rounded = round($value * 2) / 2;

        $this->expected_duration = (string) max($rounded, 0.5);
    }

    /**
     * Validate min_players <= max_players.
     */
    public function updatedMinPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    public function updatedMaxPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    protected function validatePlayerCounts(): void
    {
        if (
            $this->min_players !== null
            && $this->max_players !== null
            && $this->min_players > $this->max_players
        ) {
            $this->addError('min_players', __('Min players cannot exceed max players.'));
        }
    }

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
    public function experienceLevelOptions(): array
    {
        $options = ['' => __('Any')];
        foreach (ExperienceLevel::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    #[Computed]
    public function vibeFlagGroups(): array
    {
        return VibeFlag::grouped();
    }

    public function save(): void
    {
        $this->authorize('create', Game::class);
        $validated = $this->validate();

        // Cross-field validation after individual field validation
        if (
            isset($validated['min_players'], $validated['max_players'])
            && $validated['min_players'] > $validated['max_players']
        ) {
            $this->addError('min_players', __('Min players cannot exceed max players.'));

            return;
        }

        $game = Game::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'name' => $validated['name'],
            'date_time' => $validated['date_time'],
            'description' => $validated['description'],
            'expected_duration' => $validated['expected_duration'] ?: 2,
            'price' => $validated['price'] ?: 0,
            'language' => $validated['language'],
            'location' => [
                'details' => $validated['location_details'],
            ],
            'status' => 'scheduled',
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] ?: null,
            'safety_rules' => $validated['safety_rules'] ?: null,
            'min_players' => $validated['min_players'],
            'max_players' => $validated['max_players'],
            'experience_level' => $validated['experience_level'],
            'complexity' => $validated['complexity'] ?: null,
            'vibe_flags' => ! empty($validated['vibe_flags']) ? $validated['vibe_flags'] : null,
        ]);

        Log::info('Game created', [
            'game_id' => $game->id,
            'name' => $game->name,
            'owner_id' => Auth::id(),
        ]);

        session()->flash('success', __('Game ":name" created successfully!', ['name' => $game->name]));

        $this->redirect(route('games.detail', $game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.games.create-game');
    }
}
