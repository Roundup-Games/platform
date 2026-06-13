<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class BrowseTeams extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'newest';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $teams = Team::query()
            ->where('is_active', true)
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $escaped = $this->escapeLikeWildcards($this->search);
                $q->where('name', $this->likeOperator(), "%{$escaped}%")
                    ->orWhere('city', $this->likeOperator(), "%{$escaped}%")
                    ->orWhere('country', $this->likeOperator(), "%{$escaped}%");
            }))
            ->withCount('activeMembers')
            ->when($this->sort === 'newest', fn ($q) => $q->orderByDesc('created_at'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($this->sort === 'members', fn ($q) => $q->orderByDesc('active_members_count'))
            ->paginate(12);

        return view('livewire.teams.browse-teams', [
            'teams' => $teams,
        ]);
    }
}
