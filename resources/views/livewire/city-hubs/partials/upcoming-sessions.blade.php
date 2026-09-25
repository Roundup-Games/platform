{{-- ── Upcoming sessions section (M062 T03) ─────────────────────────────────
     Mixed-entity feed: games (both discovery forks), campaigns, and events
     merged chronologically by CityDirectoryService::upcomingSessions() and
     tagged with hub_item_type. Each type renders through its existing
     discovery/public card (game-card, campaign-card, x-event-card) — no
     new card markup to drift from the design system. The page only renders
     for qualifying clusters, but the list itself may still be empty (a
     venue-qualified city): that case gets the translated empty state with
     a discovery link, never a dead panel.
     Full lists link onward: the two discovery forks and the city-filtered
     event listing (EventListing's q matches the city column). --}}
<section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="city-hub-upcoming-heading">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
            <h2 id="city-hub-upcoming-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">event_upcoming</span>
                {{ __('city-hubs.sections.upcoming_sessions') }}
            </h2>

            <p class="mt-1 text-sm text-on-surface-variant">
                {{ __('city-hubs.stats.upcoming_sessions', ['count' => $city->upcomingActivityCount()]) }}
            </p>
        </div>
    </div>

    @if($sessions->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($sessions as $item)
                @if($item->hub_item_type === 'game')
                    @include('livewire.discovery.partials.game-card', ['game' => $item])
                @elseif($item->hub_item_type === 'campaign')
                    @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                @else
                    <x-event-card :event="$item" />
                @endif
            @endforeach
        </div>
    @else
        {{-- Empty state: real copy + onward link, never a dead panel. --}}
        <div class="rounded-xl border border-dashed border-outline-variant/50 p-8 text-center">
            <span class="material-symbols-outlined text-3xl text-on-surface-variant/70" aria-hidden="true">event_busy</span>
            <p class="mt-2 text-on-surface-variant">
                {{ __('city-hubs.empty.upcoming_sessions', ['city' => $city->city]) }}
            </p>
            <a href="{{ route('discover', app()->getLocale()) }}" wire:navigate
               class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                {{ __('city-hubs.empty.upcoming_sessions_cta') }}
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
            </a>
        </div>
    @endif

    {{-- Onward links to the full discovery lists, city-scoped via the
         URL-addressable ?city= filter (62-03): qualifying values canonicalize
         to this hub, so the links stay on-message for SEO. The event
         listing's q filter matches city and stays city-scoped. --}}
    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
        <a href="{{ route('discover.board-games', app()->getLocale()).'?city='.$city->slug }}" wire:navigate
           class="inline-flex items-center gap-1 font-medium text-primary hover:underline">
            {{ __('city-hubs.lists.view_all_board_games') }}
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
        </a>
        <a href="{{ route('discover.adventures', app()->getLocale()).'?city='.$city->slug }}" wire:navigate
           class="inline-flex items-center gap-1 font-medium text-primary hover:underline">
            {{ __('city-hubs.lists.view_all_adventures') }}
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
        </a>
        <a href="{{ route('events.index', ['locale' => app()->getLocale(), 'q' => $city->city]) }}" wire:navigate
           class="inline-flex items-center gap-1 font-medium text-primary hover:underline">
            {{ __('city-hubs.lists.view_all_events', ['city' => $city->city]) }}
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
        </a>
    </div>
</section>
