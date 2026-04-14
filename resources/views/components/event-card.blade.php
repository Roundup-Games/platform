@props(['event'])
@php
$locale = app()->getLocale();
$translatedName = $event->getTranslation($locale, 'name') ?? $event->name;
$translatedShortDescription = $event->getTranslation($locale, 'short_description') ?? $event->short_description;
// Detect fallback: no translation row for current locale but entity has content
$hasTranslationRow = $event->relationLoaded('translations')
    && $event->translations->first(fn ($t) => $t->locale === $locale && $t->field === 'name') !== null;
$hasFallback = !$hasTranslationRow && $event->name !== null;
@endphp

<div class="bg-surface-container-lowest rounded-xl shadow-ambient overflow-hidden hover:shadow-ambient-md transition-shadow duration-200">
    <a href="{{ route('events.detail', $event->slug) }}" wire:navigate class="block">
        {{-- Card Header: gradient overlay on primary --}}
        <div class="relative bg-gradient-to-br from-primary to-primary-container px-4 py-3">
            <div class="flex items-center justify-between">
                @if($event->is_featured)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-fixed">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">star</span>
                        {{ __('Featured') }}
                    </span>
                @else
                    <span>&nbsp;</span>
                @endif
                @if($event->status === 'registration_open')
                    <span class="text-xs font-medium text-on-primary/80 bg-on-primary/20 backdrop-blur-sm px-2 py-0.5 rounded-full">{{ __('Registration Open') }}</span>
                @endif
            </div>
        </div>

        {{-- Card Body --}}
        <div class="p-4">
            <h3 class="font-heading font-semibold text-on-surface text-lg leading-tight line-clamp-2">{{ $translatedName }}</h3>

            @if($hasFallback)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container mt-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">translate</span>
                    {{ __('Available in: :locale', ['locale' => $locale === 'de' ? 'English' : 'Deutsch']) }}
                </span>
            @endif

            @if($translatedShortDescription)
                <p class="mt-1 text-sm text-on-surface-variant line-clamp-2">{{ $translatedShortDescription }}</p>
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
            </div>

            {{-- Fee / Capacity footer — tonal separation, no border --}}
            <div class="mt-4 pt-3 bg-surface-container-low bg-clip-padding flex items-center justify-between">
                <span class="text-sm font-medium {{ ($event->individual_registration_fee || $event->team_registration_fee) ? 'text-primary' : 'text-secondary' }}">
                    @if($event->individual_registration_fee || $event->team_registration_fee)
                        {{ __(':amount+ to register', ['amount' => format_currency($event->individual_registration_fee ?: $event->team_registration_fee)]) }}
                    @else
                        {{ __('Free Entry') }}
                    @endif
                </span>
                <span class="text-xs text-primary font-medium hover:underline">{{ __('View Details →') }}</span>
            </div>
        </div>
    </a>
</div>
