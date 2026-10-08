@props(['event'])

{{--
    The sole event-card markup authority (used by the events listing and the
    city-hub upcoming partial). Follows the platform card language established
    by the discovery game-card: surface background, thin accent strip, badges
    inline in the body — not the old heavy primary header block, which read as
    a clashing rounded slab on every grid it appeared in.
--}}
<a href="{{ route('events.detail', $event->slug) }}" wire:navigate
   class="block bg-surface rounded-xl shadow-ambient hover:shadow-ambient-md transition-shadow duration-200 overflow-hidden group min-h-[220px]">
    <div class="h-1.5 bg-primary/60"></div>

    <div class="p-5">
        {{-- Title + status chips (game-card title-row pattern) --}}
        <div class="flex items-start justify-between gap-2 mb-2">
            <h3 class="font-heading font-semibold text-lg text-on-surface leading-tight tracking-tight line-clamp-2 group-hover:text-primary transition-colors">
                {{ $event->name }}
            </h3>
            <div class="shrink-0 flex flex-col items-end gap-1">
                @if($event->status->value === 'registration_open')
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary whitespace-nowrap">
                        {{ __('events.content_registration_open') }}
                    </span>
                @endif
                @if($event->is_featured)
                    <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container whitespace-nowrap">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">star</span>
                        {{ __('discovery.content_featured') }}
                    </span>
                @endif
            </div>
        </div>

        @if($event->short_description)
            <p class="text-sm text-on-surface-variant line-clamp-2 mb-3">{{ $event->short_description }}</p>
        @endif

        <div class="space-y-1.5">
            {{-- Date --}}
            <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">calendar_month</span>
                @if($event->start_date && $event->end_date)
                    {{ format_date($event->start_date, 'short_date') }} – {{ format_date($event->end_date, 'date') }}
                @elseif($event->start_date)
                    {{ format_date($event->start_date, 'date') }}
                @endif
            </div>

            {{-- Location — M053/S1/T06: routed through <x-location-display>
                 (the sole address-rendering authority) so no raw city leaks.
                 Events carry denormalized city fields (no Location owner),
                 so this uses the raw-city path at City granularity. --}}
            @if($event->city || $event->country)
                <div class="text-sm text-on-surface-variant">
                    <x-location-display :city="$event->city" :country="$event->country" icon-class="text-primary text-base" />
                </div>
            @endif

            {{-- Derived offering — M063/S06/T02: the umbrella's honest full
                 offering (cached union of every table's gameSystems, R051
                 applied across the get-together). Only when non-empty —
                 every table offers ≥1 system by invariant, so an empty
                 union means no tables yet (fail-closed, no phantom line). --}}
            @php($offeredSystems = $event->offeredSystems())
            @if($offeredSystems->isNotEmpty())
                <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">casino</span>
                    {{ trans_choice('games.content_n_games_on_offer', $offeredSystems->count()) }}
                </div>
            @endif

            {{-- Type --}}
            @if($event->type)
                <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">sell</span>
                    {{ $event->type?->label() }}
                </div>
            @endif

            {{-- Language --}}
            <div class="pt-1">
                <x-language-chip :language="$event->language" />
            </div>
        </div>

        {{-- Fee footer + explicit affordance --}}
        <div class="mt-4 pt-3 flex items-center justify-between">
            <span class="text-sm font-medium {{ $event->individual_registration_fee ? 'text-primary' : 'text-secondary' }}">
                @if($event->individual_registration_fee)
                    {{ __('auth.field_amount_to_register', ['amount' => format_currency($event->individual_registration_fee)]) }}
                @else
                    {{ __('billing.content_free_entry') }}
                @endif
            </span>
            <span class="text-xs text-primary font-medium group-hover:underline">{{ __('common.action_view_details') }}</span>
        </div>
    </div>
</a>
