<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateGame extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|exists:game_systems,id')]
    public ?string $game_system_id = null;

    #[Validate('required|date')]
    public string $date_time = '';

    #[Validate('nullable|string|max:5000')]
    public string $description = '';

    #[Validate('nullable|numeric|min:0.5|max:24')]
    public ?string $expected_duration = '';

    #[Validate('nullable|numeric|min:0')]
    public ?string $price = '';

    #[Validate('nullable|string|max:10')]
    public string $language = 'en';

    #[Validate('nullable|string|max:1000')]
    public string $location_details = '';

    #[Validate('required|in:public,protected,private')]
    public string $visibility = 'public';

    #[Validate('nullable|array')]
    public array $minimum_requirements = [];

    #[Validate('nullable|array')]
    public array $safety_rules = [];

    public function save(): void
    {
        $this->authorize('create', Game::class);

        $validated = $this->validate();

        $game = Game::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'name' => $validated['name'],
            'date_time' => $validated['date_time'],
            'description' => $validated['description'],
            'expected_duration' => $validated['expected_duration'] ?: 3,
            'price' => $validated['price'] ?: 0,
            'language' => $validated['language'],
            'location' => [
                'details' => $validated['location_details'],
            ],
            'status' => 'scheduled',
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] ?: null,
            'safety_rules' => $validated['safety_rules'] ?: null,
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
        return view('livewire.games.create-game', [
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }
}
