@section('title', __('campaigns.content_sessions_near_you'))

<div id="nearby-page"
     x-data="{
         loading: false,
         hasLocation: {{ $this->hasGuestLocation() ? 'true' : 'false' }},
         activeRadius: {{ $radius }},
     }"
     x-on:guest-location-updated.window="hasLocation = true; loading = false; $wire.$refresh()"
     class="min-h-[70vh]">

    {{-- ── Full-page location CTA (no location stored) ────────── --}}
    @unless($this->hasGuestLocation())
        <section class="flex flex-col items-center justify-center min-h-[60vh] px-4 py-16"
                 aria-label="{{ __('location.action_share_your_location') }}">

            <div class="text-center max-w-lg">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-primary-container mb-6">
                    <span class="material-symbols-outlined text-on-primary-container text-4xl" aria-hidden="true">explore</span>
                </div>

                <h1 class="text-3xl sm:text-4xl font-heading font-bold text-on-surface mb-4">
                    {{ __('campaigns.action_find_sessions_near_you') }}
                </h1>
                <p class="text-on-surface-variant text-lg mb-8 max-w-md mx-auto">
                    {{ __('discovery.content_share_your_location_to_discover_game_sessions') }}
                </p>

                {{-- Primary CTA: Browser geolocation --}}
                <button wire:click="locateMe"
                        x-on:click="loading = true"
                        class="inline-flex items-center px-8 py-4 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container hover:text-on-primary-container transition-colors text-base shadow-lg"
                        aria-label="{{ __('location.action_share_your_location') }}">
                    <span class="material-symbols-outlined mr-2 text-xl" aria-hidden="true">my_location</span>
                    {{ __('campaigns.content_show_me_sessions_near_me') }}
                </button>

                {{-- Manual city input --}}
                <div class="mt-6">
                    <button x-on:click="$refs.cityForm.classList.toggle('hidden')"
                            class="text-sm text-primary font-medium hover:underline focus:outline-none focus:underline">
                        {{ __('location.field_or_enter_your_city') }}
                    </button>

                    <div x-ref="cityForm" class="hidden mt-4 max-w-sm mx-auto">
                        <form wire:submit.prevent="searchCity" class="flex gap-2">
                            <div class="flex-1">
                                <input type="text"
                                       wire:model="cityQuery"
                                       placeholder="{{ __('location.field_enter_your_city') }}"
                                       class="w-full px-4 py-3 rounded-xl border border-outline bg-surface-container-low text-on-surface placeholder:text-on-surface-variant focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                                       aria-label="{{ __('location.field_city_name') }}" />
                            </div>
                            <button type="submit"
                                    class="inline-flex items-center justify-center px-4 py-3 bg-secondary-container text-on-secondary-container rounded-xl font-semibold hover:bg-secondary transition-colors text-sm"
                                    aria-label="{{ __('discovery.action_search') }}">
                                <span class="material-symbols-outlined text-lg" aria-hidden="true">search</span>
                            </button>
                        </form>
                        @error('cityQuery')
                            <p class="text-sm text-error mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </section>
    @else

        {{-- ── Location established: show radius toggle + grouped sessions ── --}}
        <section class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

            {{-- Page header --}}
            <div class="mb-8">
                <h1 class="text-2xl sm:text-3xl font-heading font-bold text-on-surface mb-2">
                    {{ __('campaigns.content_sessions_near_you') }}
                </h1>
                <p class="text-on-surface-variant">
                    {{ __('campaigns.content_showing_public_sessions_and_campaigns') }}
                </p>
            </div>

            {{-- Radius toggle --}}
            <div class="flex flex-wrap items-center gap-2 mb-6"
                 role="radiogroup"
                 aria-label="{{ __('discovery.action_search_radius') }}">
                <span class="text-sm font-medium text-on-surface-variant mr-2">
                    <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">straighten</span>
                    {{ __('common.content_radius') }}:
                </span>
                @foreach(App\Livewire\Nearby\NearbyPage::RADIUS_OPTIONS as $option)
                    <button wire:click="setRadius({{ $option }})"
                            x-on:click="activeRadius = {{ $option }}"
                            class="px-4 py-2 rounded-full text-sm font-semibold transition-colors {{
                                $radius == $option
                                    ? 'bg-primary text-on-primary shadow-sm'
                                    : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high'
                            }}"
                            role="radio"
                            aria-checked="{{ $radius == $option ? 'true' : 'false' }}">
                        {{ $option }} km
                    </button>
                @endforeach
            </div>

            {{-- Fallback radius notice --}}
            @if($usingFallbackRadius)
                <div class="mb-6 p-4 bg-primary-container/30 rounded-xl border border-primary/20">
                    <p class="text-sm text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">info</span>
                        {{ __('campaigns.content_no_sessions_found_within_radius', [
                            'radius' => (int) $radius,
                            'fallback' => 100,
                        ]) }}
                    </p>
                </div>
            @endif

            {{-- Grouped sessions --}}
            @php $groups = $this->getGroupedSessions(); @endphp

            @foreach($groups as $group)
                @if($group['items']->isNotEmpty())
                    <div class="mb-10">
                        <h2 class="text-xl font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                            @if($group['key'] === 'this_week')
                                <span class="material-symbols-outlined text-primary" aria-hidden="true">calendar_today</span>
                            @elseif($group['key'] === 'coming_up')
                                <span class="material-symbols-outlined text-primary" aria-hidden="true">event_upcoming</span>
                            @else
                                <span class="material-symbols-outlined text-primary" aria-hidden="true">autorenew</span>
                            @endif
                            {{ $group['label'] }}
                            <span class="text-sm font-normal text-on-surface-variant ml-1">({{ $group['items']->count() }})</span>
                        </h2>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($group['items'] as $item)
                                @include('livewire.components.partials.session-card', [
                                    'entity' => $item->entity,
                                    'gameSystem' => $item->game_system,
                                    'distanceKm' => $item->distance_km,
                                    'participantCount' => $item->participant_count,
                                    'type' => $item->type,
                                ])
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Empty state: no sessions at all --}}
            @if($this->isEmpty)
                <div class="text-center py-16 px-4">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-primary-container mb-6">
                        <span class="material-symbols-outlined text-on-primary-container text-4xl" aria-hidden="true">group_add</span>
                    </div>
                    <h2 class="text-2xl font-heading font-semibold text-on-surface mb-3">
                        {{ __('campaigns.content_no_sessions_near_you_yet') }}
                    </h2>
                    <p class="text-on-surface-variant max-w-lg mx-auto mb-8 text-lg">
                        {{ __('campaigns.content_be_the_first_to_bring') }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-4">
                        @auth
                            <a href="{{ route('games.create') }}" wire:navigate
                               class="inline-flex items-center px-6 py-3 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container transition-colors text-sm shadow-md">
                                <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                                {{ __('campaigns.content_host_a_session') }}
                            </a>
                        @else
                            <a href="{{ route('register') }}" wire:navigate
                               class="inline-flex items-center px-6 py-3 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container transition-colors text-sm shadow-md">
                                <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                                {{ __('auth.content_sign_up_to_host') }}
                            </a>
                        @endauth
                        <button wire:click="clearGuestLocation"
                                class="inline-flex items-center px-6 py-3 bg-surface-container text-on-surface rounded-xl font-semibold hover:bg-surface-container-high transition-colors text-sm">
                            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">edit_location</span>
                            {{ __('location.action_change_location') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- Change location link (when results exist) --}}
            @if(!$this->isEmpty)
                <div class="text-center mt-4 mb-8">
                    <button wire:click="clearGuestLocation"
                            class="text-sm text-primary font-medium hover:underline focus:outline-none focus:underline inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">edit_location</span>
                        {{ __('location.action_change_location') }}
                    </button>
                </div>
            @endif

        </section>
    @endunless
</div>
