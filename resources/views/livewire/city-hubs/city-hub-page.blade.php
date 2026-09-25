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
                {{ __('city-hubs.chip_city_hub') }}
            </span>

            <h1 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight text-on-surface">
                {{ __('city-hubs.heading', ['city' => $city->city]) }}
            </h1>

            <p class="mt-3 max-w-3xl text-on-surface-variant">
                {{ __('city-hubs.intro', ['city' => $city->city]) }}
            </p>
        </div>
    </section>

    {{-- ── Sections ─────────────────────────────────────────────────────── --}}
    {{-- T02 ships the section shells; T03 populates the bodies via partials
         (upcoming-sessions, venues). Until then each section shows its live
         count from the guarded summary — real data, never a dead panel. --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6">

        {{-- Upcoming sessions shell --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="city-hub-upcoming-heading">
            <h2 id="city-hub-upcoming-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">event_upcoming</span>
                {{ __('city-hubs.sections.upcoming_sessions') }}
            </h2>

            {{-- T03: replace with @include('livewire.city-hubs.partials.upcoming-sessions') --}}
            <p class="text-sm text-on-surface-variant">
                {{ __('city-hubs.stats.upcoming_sessions', ['count' => $city->upcomingActivityCount()]) }}
            </p>
        </section>

        {{-- Verified venues shell --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="city-hub-venues-heading">
            <h2 id="city-hub-venues-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">storefront</span>
                {{ __('city-hubs.sections.venues') }}
            </h2>

            {{-- T03: replace with @include('livewire.city-hubs.partials.venues') --}}
            <p class="text-sm text-on-surface-variant">
                {{ __('city-hubs.stats.verified_venues', ['count' => $city->verifiedVenuesCount]) }}
            </p>
        </section>
    </div>
</div>
