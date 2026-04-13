<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TeamDetail extends Component
{
    public Team $team;

    public function mount(string $slug): void
    {
        $team = Team::where('slug', $slug)->firstOrFail();
        $this->authorize('view', $team);
        $this->team = $team;
    }

    public function render()
    {
        $this->team->load(['activeMembers.user', 'activeMembers' => fn ($q) => $q->orderBy('role')->orderBy('jersey_number')]);

        return view('livewire.teams.team-detail', [
            'team' => $this->team,
            'isCaptain' => Auth::check() && $this->team->isCaptain(Auth::user()),
            'isMember' => Auth::check() && $this->team->hasMember(Auth::user()),
        ]);
    }
}
