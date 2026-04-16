@props([])

{{--
    Location gate partial for NearbySessions component.
    Renders browser geolocation button and manual city input.
    Included when guest has not yet shared location.
--}}
<div class="text-center py-10 px-4"
     x-data="{ showCityInput: false, cityInput: '' }">

    {{-- Illustrative icon --}}
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary-container mb-4">
        <span class="material-symbols-outlined text-on-primary-container text-3xl" aria-hidden="true">location_on</span>
    </div>

    <h3 class="text-xl font-heading font-semibold text-on-surface mb-2">
        {{ __("What's happening near you?") }}
    </h3>
    <p class="text-on-surface-variant max-w-md mx-auto mb-6">
        {{ __('Share your location to discover game sessions and campaigns in your area.') }}
    </p>

    {{-- Primary CTA: Browser geolocation --}}
    <div class="flex flex-col items-center gap-3">
        <button wire:click="locateMe"
                x-on:click="loading = true"
                class="inline-flex items-center px-6 py-3 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container hover:text-on-primary-container transition-colors text-sm shadow-md"
                aria-label="{{ __('Share your location') }}">
            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">my_location</span>
            {{ __('Show me sessions near me') }}
        </button>

        <button x-on:click="showCityInput = !showCityInput"
                class="text-sm text-primary font-medium hover:underline focus:outline-none focus:underline"
                aria-expanded="showCityInput">
            {{ __('Or enter your city') }}
        </button>
    </div>

    {{-- Manual city input --}}
    <div x-show="showCityInput"
         x-transition
         class="mt-4 max-w-sm mx-auto">
        <form wire:submit.prevent="searchCity" class="flex gap-2">
            <div class="flex-1 relative">
                <input type="text"
                       wire:model="cityQuery"
                       placeholder="{{ __('Enter your city') }}"
                       class="w-full px-4 py-2.5 rounded-xl border border-outline bg-surface-container-low text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                       aria-label="{{ __('City name') }}" />
            </div>
            <button type="submit"
                    class="inline-flex items-center justify-center px-4 py-2.5 bg-secondary-container text-on-secondary-container rounded-xl font-semibold hover:bg-secondary transition-colors text-sm"
                    aria-label="{{ __('Search') }}">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">search</span>
            </button>
        </form>
        @error('cityQuery')
            <p class="text-sm text-error mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>
