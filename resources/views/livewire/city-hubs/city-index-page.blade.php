<div>
    {{-- Back link — same discovery back-path as the hub pages --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('common.action_back_to_discover') }}
            </a>
        </div>
    </div>

    {{-- ── Hero / program explanation ─────────────────────────────────────── --}}
    {{-- One paragraph explaining the city-hub program: what a hub aggregates
         and that the directory grows with local activity. Mirrors the hub
         hero's markup language (badge, gradient strip, heading scale). --}}
    <section class="bg-surface-container-low">
        <div class="h-1.5 bg-gradient-to-r from-primary/80 via-tertiary to-primary/80"></div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-secondary-container/60 text-on-secondary-container">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">location_on</span>
                {{ __('city-hubs.label_city_hubs') }}
            </span>

            <h1 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight text-on-surface">
                {{ __('city-hubs.heading_city_hubs_index') }}
            </h1>

            <p class="mt-3 max-w-3xl text-on-surface-variant">
                {{ __('city-hubs.content_index_intro') }}
            </p>
        </div>
    </section>

    {{-- ── City directory ─────────────────────────────────────────────────── --}}
    {{-- Every card links to a hub CityDirectoryService currently considers
         servable (qualifyingCities() filters exactly like the hub guard),
         so no card can ever point at a 404. Featured curation sorts first. --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
        @if ($cities->isNotEmpty())
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list">
                @foreach ($cities as $city)
                    <li>
                        <a href="{{ route('city-hubs.show', ['locale' => app()->getLocale(), 'slug' => $city->slug]) }}" wire:navigate
                           aria-label="{{ __('discovery.action_view_city_hub', ['city' => $city->city]) }}"
                           class="flex flex-col h-full bg-surface rounded-2xl shadow-ambient border border-outline-variant/15 p-5 hover:border-primary/40 hover:shadow-md transition-all group">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-primary-container text-on-primary-container shrink-0">
                                    <span class="material-symbols-outlined text-xl" aria-hidden="true">location_city</span>
                                </span>
                                <h2 class="text-lg font-heading font-semibold text-on-surface group-hover:text-primary transition-colors truncate">{{ $city->city }}</h2>
                            </div>

                            @if ($city->featured)
                                <span class="mt-3 self-start inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container/60 text-on-secondary-container">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">star</span>
                                    {{ __('city-hubs.label_featured') }}
                                </span>
                            @endif

                            <div class="mt-3 pt-3 border-t border-outline-variant/10 flex flex-col gap-1 text-sm text-on-surface-variant">
                                <span>{{ __('city-hubs.label_upcoming_sessions_count', ['count' => $city->upcomingActivityCount()]) }}</span>
                                <span>{{ __('city-hubs.label_verified_venues_count', ['count' => $city->verifiedVenuesCount]) }}</span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- Empty states always link onward — never a dead panel. --}}
            <div class="bg-surface rounded-2xl shadow-ambient p-8 text-center">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-surface-container-low text-on-surface-variant">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">location_city</span>
                </span>
                <p class="mt-4 text-on-surface-variant">{{ __('city-hubs.empty_city_index') }}</p>
                <a href="{{ route('discover') }}" wire:navigate
                   class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium bg-primary text-on-primary hover:opacity-90 transition-opacity">
                    {{ __('city-hubs.empty_city_index_cta') }}
                </a>
            </div>
        @endif
    </div>
</div>
