<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.public-layout')]
class EventDetail extends Component
{
    public Event $event;

    public function mount(string $slug): void
    {
        $event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('view', $event);
        $this->event = $event;
    }

    public function render()
    {
        $this->event->load([
            'announcements' => fn ($q) => $q->published()->orderByDesc('is_pinned')->orderByDesc('created_at'),
            'registrations',
        ]);

        $counts = $this->event->registrations()
            ->selectRaw('registration_type, count(*) as count')
            ->groupBy('registration_type')
            ->pluck('count', 'registration_type');

        $teamCount = $counts->get('team', 0);
        $individualCount = $counts->get('individual', 0);

        return view('livewire.events.event-detail', [
            'announcements' => $this->event->announcements,
            'teamCount' => $teamCount,
            'individualCount' => $individualCount,
        ]);
    }
}
