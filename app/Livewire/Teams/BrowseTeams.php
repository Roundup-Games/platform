<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class BrowseTeams extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'newest';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $teams = Team::query()
            ->where('is_active', true)
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('city', 'like', "%{$this->search}%")
                  ->orWhere('country', 'like', "%{$this->search}%");
            }))
            ->when($this->sort === 'newest', fn ($q) => $q->orderByDesc('created_at'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($this->sort === 'members', fn ($q) => $q->withCount('activeMembers')->orderByDesc('active_members_count'))
            ->withCount('activeMembers')
            ->paginate(12);

        return view('livewire.teams.browse-teams', [
            'teams' => $teams,
        ]);
    }
}
