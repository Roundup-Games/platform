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

        $teamCount = $this->event->registrations()->where('registration_type', 'team')->count();
        $individualCount = $this->event->registrations()->where('registration_type', 'individual')->count();

        return view('livewire.events.event-detail', [
            'announcements' => $this->event->announcements,
            'teamCount' => $teamCount,
            'individualCount' => $individualCount,
        ]);
    }
}
