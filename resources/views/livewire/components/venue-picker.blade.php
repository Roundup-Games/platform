@props([
    'showInstructions' => true,
])

@php
    $selectedVenue = $this->selectedVenue;
    $selectedIsVenue = $this->selectedIsVenue;
@endphp

<div>
    @if($editing && !$locationConfirmed)
        {{-- Mode toggle --}}
        <div class="flex gap-1 mb-4 bg-surface-container-high rounded-lg p-1">
            <button type="button"
                    wire:click="switchMode('venue')"
                    @class([
                        'flex-1 px-3 py-2 rounded-md text-sm font-medium transition-all duration-150',
                        'bg-primary text-on-primary shadow-xs' => $mode === 'venue',
                        'text-on-surface-variant hover:text-on-surface' => $mode !== 'venue',
                    ])>
                <span class="material-symbols-outlined text-sm align-middle mr-1" style="font-variation-settings: 'FILL' 1">store</span>
                {{ __('venues.action_search_venues') }}
            </button>
            <button type="button"
                    wire:click="switchMode('address')"
                    @class([
                        'flex-1 px-3 py-2 rounded-md text-sm font-medium transition-all duration-150',
                        'bg-primary text-on-primary shadow-xs' => $mode === 'address',
                        'text-on-surface-variant hover:text-on-surface' => $mode !== 'address',
                    ])>
                <span class="material-symbols-outlined text-sm align-middle mr-1">pin_drop</span>
                {{ __('venues.action_search_address') }}
            </button>
        </div>

        @if($mode === 'venue')
            {{-- Venue Search Mode --}}
            <div class="space-y-3">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" style="font-variation-settings: 'FILL' 1">search</span>
                    <input type="text"
                           wire:model="venueQuery"
                           wire:keydown.enter="searchVenues"
                           placeholder="{{ __('venues.placeholder_search_venues') }}"
                           class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant text-sm" />
                </div>

                <button type="button" wire:click="searchVenues"
                        class="w-full px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                    <span wire:loading.remove wire:target="searchVenues">{{ __('venues.action_find_venues') }}</span>
                    <span wire:loading wire:target="searchVenues">{{ __('common.content_searching') }}</span>
                </button>

                @error('venueQuery') <p class="text-sm text-error">{{ $message }}</p> @enderror

                {{-- Results --}}
                @if($venueSearchPerformed)
                    <div class="space-y-1 max-h-64 overflow-y-auto">
                        @forelse($venues as $venue)
                            <button type="button" wire:click="selectVenue('{{ $venue['id'] }}')"
                                    class="w-full text-left px-3 py-2.5 rounded-lg hover:bg-surface-container-high transition-colors group">
                                <div class="flex items-center justify-between">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                                            {{ $venue['name'] }}
                                        </p>
                                        <p class="text-xs text-on-surface-variant truncate">
                                            {{ $venue['city'] }}
                                            @if($venue['address'])
                                                &middot; {{ $venue['address'] }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 ml-3 shrink-0">
                                        @if($venue['venue_type'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary-container/40 text-on-primary-container">
                                                {{ __('location.type_' . $venue['venue_type']) }}
                                            </span>
                                        @endif
                                        @if($venue['distance_km'] !== null)
                                            <span class="text-xs text-on-surface-variant tabular-nums">
                                                <x-distance-display :precise-km="$venue['distance_km']" precise icon="" />
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </button>
                        @empty
                            <div class="text-center py-6">
                                <span class="material-symbols-outlined text-3xl text-on-surface-variant/40 mb-2">storefront</span>
                                <p class="text-sm text-on-surface-variant">{{ __('venues.content_no_venues_found') }}</p>
                                <a href="{{ route('venues.propose', ['locale' => app()->getLocale()]) }}"
                                   class="text-sm text-primary hover:underline mt-2 inline-block"
                                   wire:navigate>
                                    {{ __('venues.action_propose_venue') }} &rarr;
                                </a>
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>
        @else
            {{-- Address Search Mode --}}
            <div class="space-y-4">
                <div>
                    <label for="vp-city" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('location.field_city') }} <span class="text-error">*</span>
                    </label>
                    <input type="text" id="vp-city" wire:model="city" placeholder="{{ __('location.field_enter_your_city') }}"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="vp-address" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('location.field_address_optional') }}
                    </label>
                    <input type="text" id="vp-address" wire:model="address" placeholder="{{ __('location.placeholder_street_address_neighborhood') }}"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                </div>

                <button type="button" wire:click="confirmAddress"
                        class="w-full px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                    <span wire:loading.remove wire:target="confirmAddress">{{ __('venues.action_save_address') }}</span>
                    <span wire:loading wire:target="confirmAddress">{{ __('common.content_searching') }}</span>
                </button>
            </div>
        @endif

        <button type="button" wire:click="cancelEditing"
                class="text-sm text-on-surface-variant hover:text-primary transition-colors mt-3">
            {{ __('common.action_cancel') }}
        </button>

    @elseif($locationConfirmed && $locationId)
        {{-- Location confirmed --}}
        <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
            <div class="flex items-center gap-2 min-w-0">
                @if($selectedIsVenue)
                    <span class="material-symbols-outlined text-lg text-primary shrink-0" style="font-variation-settings: 'FILL' 1">store</span>
                @else
                    <span class="material-symbols-outlined text-lg text-primary shrink-0" style="font-variation-settings: 'FILL' 1">pin_drop</span>
                @endif
                <div class="min-w-0">
                    @if($selectedVenue)
                        <span class="text-sm text-on-surface font-medium">{{ $selectedVenue->name }}</span>
                        @if($selectedVenue->city && $selectedVenue->city !== $selectedVenue->name)
                            <span class="text-xs text-on-surface-variant ml-1">{{ $selectedVenue->city }}</span>
                        @endif
                        @if($selectedIsVenue && $selectedVenue->venue_type)
                            <span class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary-container/40 text-on-primary-container">
                                {{ __('location.type_' . $selectedVenue->venue_type->value) }}
                            </span>
                        @endif
                    @else
                        <span class="text-sm text-on-surface">{{ $city }}</span>
                        @if($address)
                            <p class="text-xs text-on-surface-variant">{{ $address }}</p>
                        @endif
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" wire:click="startEditing"
                        class="text-xs text-on-surface-variant hover:text-primary transition-colors">
                    {{ __('common.action_edit') }}
                </button>
                <button type="button" wire:click="removeLocation"
                        class="text-xs text-error hover:brightness-110 transition-colors">
                    {{ __('common.action_remove') }}
                </button>
            </div>
        </div>

        {{-- Disclosure-consequence preview (T08): shows the organizer what a
             stranger viewer will see for this location, computed via the same
             LocationDisclosureService that governs the real rendered value. --}}
        @php $preview = $this->disclosurePreview; @endphp
        @if($preview !== null)
            <div class="mt-2 flex items-start gap-2 p-2.5 rounded-lg bg-tertiary-container/30 border border-tertiary/10" role="status">
                <span class="material-symbols-outlined text-base text-on-surface-variant shrink-0 mt-0.5" aria-hidden="true">visibility</span>
                <div class="min-w-0">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-on-surface-variant">
                        {{ __('location.content_preview_heading') }}
                    </p>
                    @if($preview['level'] === 'exact' && $preview['address'])
                        <p class="text-sm text-on-surface break-words">
                            {{ __('location.content_preview_exact', ['address' => $preview['address']]) }}
                        </p>
                    @else
                        <p class="text-sm text-on-surface">
                            {{ __('location.content_preview_area') }}
                        </p>
                    @endif
                </div>
            </div>
        @endif

    @else
        {{-- No location set — prompt --}}
        <div class="text-center py-4">
            <button type="button" wire:click="startEditing"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">store</span>
                {{ __('venues.action_choose_venue') }}
            </button>
            <p class="text-xs text-on-surface-variant/60 mt-2">{{ __('venues.hint_venue_primary_cta') }}</p>
        </div>
    @endif

    {{-- Location Instructions (always visible when enabled) --}}
    @if($showInstructions)
        <div class="mt-3">
            <label for="vp-instructions" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('venues.field_location_instructions') }}
            </label>
            <input type="text" id="vp-instructions"
                   wire:model="locationInstructions"
                   placeholder="{{ __('venues.placeholder_location_instructions') }}"
                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant text-sm" />
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('venues.hint_location_instructions') }}</p>
        </div>
    @endif
</div>
