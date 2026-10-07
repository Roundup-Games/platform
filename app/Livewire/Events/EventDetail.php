<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.public-layout')]
class EventDetail extends Component
{
    /**
     * Tables rendered before the "Show all" expansion — a page-weight guard
     * for umbrella events with many tables (UI spec §3.4 caps the initial
     * map at 12 rows; the full list stays one toggle away).
     */
    public const TABLES_PAGE_SIZE = 12;

    public Event $event;

    /** Expand the tables map beyond the initial cap. */
    public bool $showAllTables = false;

    public function mount(string $slug): void
    {
        $event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('view', $event);
        $this->event = $event;
    }

    public function render(): View
    {
        $this->event->load([
            'announcements' => fn ($q) => $q->published()->visibleTo(auth()->user(), $this->event)->orderByDesc('is_pinned')->orderByDesc('created_at'),
            'registrations',
            // The map of the day: every table with its host, the offered
            // systems (R051 honest rendering) and the seat aggregates in one
            // eager pass — the section renders without per-table queries.
            'tables' => fn ($q) => $q->with(['owner', 'gameSystems'])->withCount([
                'participants as approved_participants_count' => fn ($q) => $q->where('status', 'approved'),
                'participants as waitlisted_participants_count' => fn ($q) => $q->where('status', 'waitlisted'),
            ]),
        ]);

        // Derived offering FIRST: computes in-memory from the eager load
        // above and writes through to the per-event cache. seo()->for()
        // below re-derives SEO data on a fresh event instance (the seo
        // row's morphTo inverse), so the warm key keeps its about()
        // enrichment zero-query on this pass (same pattern as
        // PublicGameDetail's load-then-seo ordering).
        $offeredSystems = $this->event->offeredSystems();

        seo()->for($this->event);

        $user = auth()->user();

        // Registrant self-state: the viewer's active registration. Cancelled
        // rows don't count — same active set the duplicate check on
        // RegisterForEvent uses.
        /** @var EventRegistration|null $userRegistration */
        $userRegistration = $user === null
            ? null
            : $this->event->registrations->first(
                fn (EventRegistration $registration): bool => $registration->user_id === $user->id
                    && $registration->status !== 'cancelled'
            );

        $tables = $this->event->tables;

        return view('livewire.events.event-detail', [
            'announcements' => $this->event->announcements,
            'individualCount' => $this->event->registrations->count(),
            'tables' => $this->showAllTables ? $tables : $tables->take(self::TABLES_PAGE_SIZE),
            'tablesTotal' => $tables->count(),
            // Derived offering for the hero offering summary (M063/S06/T02).
            // Zero extra queries: computed above from the eager load.
            'offeredSystemsCount' => $offeredSystems->count(),
            'userRegistration' => $userRegistration,
            'isEventManager' => $user !== null && $user->can('update', $this->event),
        ]);
    }
}
