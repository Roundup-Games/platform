@props(['event'])

<div class="bg-surface-container-lowest rounded-xl shadow-ambient overflow-hidden hover:shadow-ambient-md transition-shadow duration-200">
    <a href="{{ route('events.detail', $event->slug) }}" wire:navigate class="block">
        {{-- Card Header --}}
        <div class="relative bg-primary px-4 py-3">
            <div class="flex items-center justify-between">
                @if($event->is_featured)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-fixed">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">star</span>
                        {{ __('discovery.content_featured') }}
                    </span>
                @else
                    <span>&nbsp;</span>
                @endif
                @if($event->status === 'registration_open')
                    <span class="text-xs font-medium text-on-primary/80 bg-on-primary/20 backdrop-blur-sm px-2 py-0.5 rounded-full">{{ __('events.content_registration_open') }}</span>
                @endif
            </div>
        </div>

        {{-- Card Body --}}
        <div class="p-4">
            <h3 class="font-heading font-semibold text-on-surface text-lg leading-tight line-clamp-2">{{ $event->name }}</h3>

            @if($event->short_description)
                <p class="mt-1 text-sm text-on-surface-variant line-clamp-2">{{ $event->short_description }}</p>
            @endif

            <div class="mt-3 space-y-1.5">
                {{-- Date --}}
                <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">calendar_month</span>
                    @if($event->start_date && $event->end_date)
                        {{ format_date($event->start_date, 'short_date') }} – {{ format_date($event->end_date, 'date') }}
                    @elseif($event->start_date)
                        {{ format_date($event->start_date, 'date') }}
                    @endif
                </div>

                {{-- Location --}}
                @if($event->city)
                    <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                        <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">location_on</span>
                        {{ $event->city }}
                    </div>
                @endif

                {{-- Type --}}
                @if($event->type)
                    <div class="flex items-center gap-2 text-sm text-on-surface-variant">
                        <span class="material-symbols-outlined text-primary text-base" aria-hidden="true">sell</span>
                        {{ ucfirst($event->type) }}
                    </div>
                @endif

                {{-- Language --}}
                <div class="mt-1">
                    <x-language-chip :language="$event->language" />
                </div>
            </div>

            {{-- Fee / Capacity footer — tonal separation, no border --}}
            <div class="mt-4 pt-3 bg-surface-container-low bg-clip-padding flex items-center justify-between">
                <span class="text-sm font-medium {{ ($event->individual_registration_fee || $event->team_registration_fee) ? 'text-primary' : 'text-secondary' }}">
                    @if($event->individual_registration_fee || $event->team_registration_fee)
                        {{ __('auth.field_amount_to_register', ['amount' => format_currency($event->individual_registration_fee ?: $event->team_registration_fee)]) }}
                    @else
                        {{ __('billing.content_free_entry') }}
                    @endif
                </span>
                <span class="text-xs text-primary font-medium hover:underline">{{ __('common.action_view_details') }}</span>
            </div>
        </div>
    </a>
</div>
