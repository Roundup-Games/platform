<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Traits\EscapesLikeWildcards;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class EventListing extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $date = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status', 'date']);
        $this->resetPage();
    }

    public function render()
    {
        $query = Event::query()
            ->where('is_public', true)
            ->whereIn('status', ['published', 'registration_open', 'registration_closed', 'in_progress'])
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $escaped = $this->escapeLikeWildcards($this->search);
                $q->where('name', 'like', "%{$escaped}%")
                  ->orWhere('city', 'like', "%{$escaped}%")
                  ->orWhere('venue_name', 'like', "%{$escaped}%");
            }))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->date === 'upcoming', fn ($q) => $q->where('start_date', '>=', now()))
            ->when($this->date === 'past', fn ($q) => $q->where('end_date', '<', now()))
            ->when($this->date === 'this_week', fn ($q) => $q->whereBetween('start_date', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($this->date === 'this_month', fn ($q) => $q->whereBetween('start_date', [now()->startOfMonth(), now()->endOfMonth()]))

            // Featured events first, then by start_date
            ->orderByDesc('is_featured')
            ->orderBy('start_date');

        $events = $query->paginate(12);

        return view('livewire.events.event-listing', [
            'events' => $events,
        ]);
    }
}
