<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\Game;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AddSessionToCampaign extends Component
{
    public Campaign $campaign;

    public string $name = '';

    public string $description = '';

    public string $date_time = '';

    public string $location_details = '';

    public function mount(string $id): void
    {
        $this->campaign = Campaign::findOrFail($id);
        $this->authorize('update', $this->campaign);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'date_time' => 'required|date',
            'location_details' => 'nullable|string|max:1000',
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Game::class);

        $validated = $this->validate();

        $game = Game::create([
            'owner_id' => Auth::id(),
            'campaign_id' => $this->campaign->id,
            'game_system_id' => $this->campaign->game_system_id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'date_time' => $validated['date_time'],
            'expected_duration' => $this->campaign->session_duration ?? 2,
            'price' => $this->campaign->price_per_session ?? 0,
            'language' => $this->campaign->language,
            'location' => [
                'details' => $validated['location_details'],
            ],
            'status' => 'scheduled',
            'visibility' => $this->campaign->visibility,
            'min_players' => $this->campaign->min_players,
            'max_players' => $this->campaign->max_players,
            'experience_level' => $this->campaign->experience_level,
            'complexity' => $this->campaign->complexity,
            'vibe_flags' => $this->campaign->vibe_flags,
        ]);

        Log::info('Game session added to campaign', [
            'game_id' => $game->id,
            'campaign_id' => $this->campaign->id,
            'owner_id' => Auth::id(),
        ]);

        session()->flash('success', __('Session ":name" added to campaign!', ['name' => $game->name]));

        $this->redirect(route('games.detail', $game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.campaigns.add-session-to-campaign');
    }
}
