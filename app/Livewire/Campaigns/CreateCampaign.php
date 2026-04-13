<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateCampaign extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|exists:game_systems,id')]
    public ?string $game_system_id = null;

    #[Validate('nullable|string|max:10000')]
    public string $description = '';

    #[Validate('required|in:weekly,bi-weekly,monthly,custom')]
    public string $recurrence = 'weekly';

    #[Validate('required|date_format:H:i')]
    public string $time_of_day = '19:00';

    #[Validate('nullable|numeric|min:0.5|max:24')]
    public ?string $session_duration = '3';

    #[Validate('nullable|numeric|min:0')]
    public ?string $price_per_session = '';

    #[Validate('nullable|string|max:10')]
    public string $language = 'en';

    #[Validate('nullable|string|max:255')]
    public string $location_type = 'online';

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
        $this->authorize('create', Campaign::class);

        $validated = $this->validate();

        $campaign = Campaign::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'recurrence' => $validated['recurrence'],
            'time_of_day' => $validated['time_of_day'],
            'session_duration' => $validated['session_duration'] ?: null,
            'price_per_session' => $validated['price_per_session'] ?: 0,
            'language' => $validated['language'],
            'location' => [
                'type' => $validated['location_type'],
                'details' => $validated['location_details'],
            ],
            'status' => 'active',
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] ?: null,
            'safety_rules' => $validated['safety_rules'] ?: null,
        ]);

        Log::info('Campaign created', [
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'owner_id' => Auth::id(),
        ]);

        session()->flash('success', 'Campaign "' . $campaign->name . '" created successfully!');

        $this->redirect(route('campaigns.detail', $campaign->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.campaigns.create-campaign', [
            'gameSystems' => GameSystem::orderBy('name')->get(),
        ]);
    }
}
