@props([])

<div id="nearby-sessions"
     x-data="{
         loading: false,
         hasLocation: {{ $this->hasGuestLocation() ? 'true' : 'false' }},
     }"
     x-on:guest-location-updated.window="hasLocation = true; loading = false; $wire.$refresh()"
     class="w-full">

    {{-- Location Gate: shown when no coordinates --}}
    @unless($this->hasGuestLocation())
        @include('livewire.components.partials.location-gate')
    @else

        {{-- Session cards --}}
        @php $sessions = $this->getSessions(); @endphp

        @if($sessions->isNotEmpty())
            <div class="space-y-4">
                @if($usingFallbackRadius)
                    <p class="text-sm text-on-surface-variant mb-2">
                        <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">info</span>
                        {{ __('No sessions found within :radius km. Showing results within :fallback km.', [
                            'radius' => (int) $radius,
                            'fallback' => (int) $fallbackRadius,
                        ]) }}
                    </p>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($sessions as $item)
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
        @else
            {{-- Empty state: organizer recruitment CTA --}}
            <div class="text-center py-12 px-4">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary-container mb-4">
                    <span class="material-symbols-outlined text-on-primary-container text-3xl" aria-hidden="true">group_add</span>
                </div>
                <h3 class="text-lg font-heading font-semibold text-on-surface mb-2">
                    {{ __('No sessions near you yet') }}
                </h3>
                <p class="text-on-surface-variant max-w-md mx-auto mb-6">
                    {{ __('Be the first to bring tabletop gaming to your area. Start a session and players will find you.') }}
                </p>
                <div class="flex flex-wrap justify-center gap-3">
                    @auth
                        <a href="{{ route('games.create') }}" wire:navigate
                           class="inline-flex items-center px-5 py-2.5 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container transition-colors text-sm">
                            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                            {{ __('Host a Session') }}
                        </a>
                    @else
                        <a href="{{ route('register') }}" wire:navigate
                           class="inline-flex items-center px-5 py-2.5 bg-primary text-on-primary rounded-xl font-semibold hover:bg-primary-container transition-colors text-sm">
                            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                            {{ __('Sign Up to Host') }}
                        </a>
                    @endauth
                    <button wire:click="clearGuestLocation"
                            class="inline-flex items-center px-5 py-2.5 bg-surface-container text-on-surface rounded-xl font-semibold hover:bg-surface-container-high transition-colors text-sm">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">edit_location</span>
                        {{ __('Change Location') }}
                    </button>
                </div>
            </div>
        @endif
    @endunless
</div>
