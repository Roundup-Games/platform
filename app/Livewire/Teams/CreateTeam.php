<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateTeam extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('nullable|string|max:255')]
    public string $city = '';

    #[Validate('nullable|string|max:3')]
    public string $country = '';

    #[Validate('nullable|string|max:7')]
    public string $primary_color = '';

    #[Validate('nullable|string|max:7')]
    public string $secondary_color = '';

    #[Validate('nullable|string|max:4')]
    public string $founded_year = '';

    public function save(): void
    {
        $this->authorize('create', Team::class);

        $validated = $this->validate();

        $team = Team::create([
            ...$validated,
            'created_by' => Auth::id(),
            'is_active' => true,
        ]);

        // Creator becomes captain
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => Auth::id(),
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Log::info('Team created', [
            'team_id' => $team->id,
            'team_slug' => $team->slug,
            'created_by' => Auth::id(),
        ]);

        session()->flash('success', 'Team "' . $team->name . '" created successfully!');

        $this->redirect(route('teams.detail', $team->slug), navigate: true);
    }

    public function render()
    {
        return view('livewire.teams.create-team');
    }
}
