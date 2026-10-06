<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('common.action_back_to_discover') }}
            </a>
        </div>
    </div>

    {{-- ── Hero / city identity ─────────────────────────────────────────── --}}
    {{-- This page only renders for city clusters that pass
         CityDirectoryService's qualification guard (CityHubPage::mount()
         404s everything else), so the hero can speak of live activity. --}}
    <section class="bg-surface-container-low">
        {{-- Brand accent strip (ties hub pages into the brand identity) --}}
        <div class="h-1.5 bg-gradient-to-r from-primary/80 via-tertiary to-primary/80"></div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-secondary-container/60 text-on-secondary-container">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">location_on</span>
                {{ __('city-hubs.label_city_hub') }}
            </span>

            <h1 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight text-on-surface">
                {{ __('city-hubs.heading_city_hub', ['city' => $city->city]) }}
            </h1>

            {{-- Curated intro (cities.intro, 62-04) when this locale has one;
                 otherwise the generated copy. introFor() trims and nulls
                 empty translations, so ?? is the full emptiness check. --}}
            <p class="mt-3 max-w-3xl text-on-surface-variant">
                {{ $intro ?? __('city-hubs.content_intro', ['city' => $city->city]) }}
            </p>
        </div>
    </section>

    {{-- ── Sections ─────────────────────────────────────────────────────── --}}
    {{-- Each section is a partial (T03): the sessions section renders the
         chronologically merged games/campaigns/events feed through the
         existing discovery cards (empty state when a venue-qualified city
         has no sessions), and the venues section lists public-venue-page
         venues via <x-venue-link>. Both keep their live counts from the
         guarded summary and link onward to the full lists. --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        @include('livewire.city-hubs.partials.upcoming-sessions', ['city' => $city, 'sessions' => $sessions])

        @include('livewire.city-hubs.partials.venues', ['city' => $city, 'venues' => $venues])
    </div>
</div>
