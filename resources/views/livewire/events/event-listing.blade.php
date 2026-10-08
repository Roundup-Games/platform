<div>
    <x-hero title="Events" :subtitle="__('events.content_discover_tournaments_leagues_camps_and')" />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        {{-- Organizer entry point: /events/create had zero inbound links —
             the only paths in were a URL or the post-create redirect. --}}
        @auth
            <div class="flex justify-end">
                <a href="{{ route('events.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-semibold shadow-xs hover:opacity-90 active:scale-[0.98] transition ease-in-out duration-150 whitespace-nowrap">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('events.action_create_event') }}
                </a>
            </div>
        @endauth

        {{-- Search & Filters --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="Search events" wire:model.live.debounce.300ms="search" placeholder="{{ __('events.action_search_events_by_name_city_or_venue') }}"
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
            </div>
            <select wire:model.live="type" aria-label="Filter by event type"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_all_types') }}</option>
                @foreach(\App\Enums\EventType::cases() as $typeCase)
                    <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" aria-label="Filter by event status"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_all_statuses') }}</option>
                <option value="registration_open">{{ __('events.content_registration_open') }}</option>
                <option value="registration_closed">{{ __('events.content_registration_closed') }}</option>
                <option value="in_progress">{{ __('common.content_in_progress') }}</option>
                <option value="published">{{ __('common.status_published') }}</option>
            </select>
            <select wire:model.live="date" aria-label="Filter by date"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.field_any_date') }}</option>
                <option value="upcoming">{{ __('common.field_upcoming') }}</option>
                <option value="this_week">{{ __('common.content_this_week') }}</option>
                <option value="this_month">{{ __('common.content_this_month') }}</option>
                <option value="past">{{ __('common.content_past') }}</option>
            </select>
        </div>

        {{-- Active filters --}}
        @if($search || $type || $status || $date)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('common.content_filters') }}</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @if($type)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ \App\Enums\EventType::tryFrom($type)?->label() ?? ucfirst($type) }}
                    </span>
                @endif
                @if($status)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        {{ __(ucfirst(str_replace('_', ' ', $status))) }}
                    </span>
                @endif
                @if($date)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                        {{ __(ucfirst(str_replace('_', ' ', $date))) }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
            </div>
        @endif

        {{-- Events Grid --}}
        @if($events->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($events as $event)
                    {{-- M063/S06/T03: dedupe — the listing renders the shared
                         <x-event-card> (the city-hub upcoming partial's card,
                         carrying the T02 derived-offering line) instead of its
                         own drift-prone copy. The component is the sole event
                         card markup authority; the listing supplies only the
                         grid. EventListing eager-loads tables.gameSystems so
                         each card's offering read stays zero-query. --}}
                    <x-event-card :event="$event" />
                @endforeach
            </div>

            <div class="mt-6">
                {{ $events->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">event_busy</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('events.content_no_events_found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($search || $type || $status || $date)
                        {{ __('common.action_try_adjusting_your_filters') }}
                    @else
                        {{ __('events.content_check_back_soon_for_upcoming_events') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
