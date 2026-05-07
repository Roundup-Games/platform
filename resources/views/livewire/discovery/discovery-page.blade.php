<div>
    @include('livewire.discovery.partials._search-header')

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5">

        @include('livewire.discovery.partials._recommendations')

        {{-- ── Top Band: Type + Time + Location ──────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">

            {{-- Type pills (replaces mode tabs) --}}
            <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1" role="radiogroup" aria-label="{{ __('campaigns.content_session_type') }}">
                <button wire:click="setMode('all')"
                        role="radio" aria-checked="{{ $mode === 'all' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'all' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_either') }}
                </button>
                <button wire:click="setMode('games')"
                        role="radio" aria-checked="{{ $mode === 'games' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'games' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_one_shot') }}
                </button>
                <button wire:click="setMode('campaigns')"
                        role="radio" aria-checked="{{ $mode === 'campaigns' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'campaigns' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('campaigns.content_campaign') }}
                </button>
            </div>

            {{-- Time pills (contextual to type) --}}
            @if($mode === 'all' || $mode === 'games')
                <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1 overflow-x-auto" role="radiogroup" aria-label="{{ __('common.field_time_frame') }}">
                    <button wire:click="setDate('')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ !$date ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('discovery.field_any_date') }}
                    </button>
                    <button wire:click="setDate('upcoming')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'upcoming' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.field_upcoming') }}
                    </button>
                    <button wire:click="setDate('this_week')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'this_week' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.content_this_week') }}
                    </button>
                    <button wire:click="setDate('this_month')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'this_month' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.content_this_month') }}
                    </button>
                </div>
            @endif

            @if($mode === 'campaigns')
                <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1 overflow-x-auto" role="radiogroup" aria-label="{{ __('campaigns.content_schedule') }}">
                    <button wire:click="setRecurrence('')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ !$recurrence ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('discovery.content_any_schedule') }}
                    </button>
                    @foreach($recurrenceOptions as $option)
                        <button wire:click="setRecurrence('{{ $option }}')"
                                class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $recurrence === $option ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                            {{ __(ucfirst(str_replace('-', ' ', $option))) }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Location line --}}
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant sm:ml-auto">
                <span class="material-symbols-outlined text-base" aria-hidden="true">location_on</span>
                @if($guestLat && $guestLng)
                    <span>{{ round($guestLat, 1) }}°, {{ round($guestLng, 1) }}°</span>
                @else
                    <span>{{ __('location.action_set_your_location') }}</span>
                @endif
                <button wire:click="requestGuestLocation" class="text-primary hover:underline text-xs">{{ __('common.action_change') }}</button>
            </div>
        </div>

        {{-- ── Radius toggle (when guest has location) ──────────── --}}
        @if($hasLocation)
            <div class="flex flex-wrap items-center gap-2"
                 role="radiogroup"
                 aria-label="{{ __('discovery.action_search_radius') }}">
                <span class="text-sm font-medium text-on-surface-variant mr-1">
                    <span class="material-symbols-outlined text-sm align-middle mr-0.5" aria-hidden="true">straighten</span>
                    {{ __('common.content_radius') }}:
                </span>
                <button wire:click="setRadius(0)"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $radius == 0 ? 'bg-tertiary-container text-on-tertiary-container shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                        role="radio"
                        aria-checked="{{ $radius == 0 ? 'true' : 'false' }}">
                    {{ __('discovery.field_any_distance') }}
                </button>
                @foreach($radiusOptions as $option)
                    <button wire:click="setRadius({{ $option }})"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $radius == $option ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                            role="radio"
                            aria-checked="{{ $radius == $option ? 'true' : 'false' }}">
                        {{ $option }} km
                    </button>
                @endforeach
            </div>
        @endif

        {{-- ── Fallback radius notice ──────────────────────────────── --}}
        @if($usingFallbackRadius)
            <div class="p-3 bg-primary-container/30 rounded-xl border border-primary/20">
                <p class="text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">info</span>
                    {{ __('campaigns.content_no_sessions_found_within_radius', [
                        'radius' => (int) $radius,
                        'fallback' => 100,
                    ]) }}
                </p>
            </div>
        @endif

        @php($activeFilters = $this->hasActiveFilters())
        @include('livewire.discovery.partials._filter-panel')

        {{-- ── Results / Empty State ──────────────────────────────────── --}}
        @if($results->count())
            @include('livewire.discovery.partials._results-grid')
        @else
            @include('livewire.discovery.partials._empty-state')
        @endif
    </div>
</div>
