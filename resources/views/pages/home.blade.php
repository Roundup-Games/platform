<x-public-layout>

    {{-- ── Hero Section ────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        {{-- Decorative background shapes --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-36">
            <div class="max-w-3xl">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                    {{ __("common.content_there_s_a_seat_waiting_for_you") }}
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-xl">
                    {{ __('events.content_find_your_people_discover_new') }}
                </p>
                {{-- M066/S09/T03: hero CTA hierarchy flipped — Discover (/discover) is
                     the primary button because it works for every guest immediately;
                     the nearby-sessions anchor is secondary since the section it
                     jumps to depends on the location gate. --}}
                <div class="mt-8 flex flex-wrap gap-4">
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('games.action_explore_games_cta') }}
                    </a>
                    <a href="#nearby-sessions"
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">near_me</span>
                        {{ __('campaigns.action_find_sessions_near_me') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Nearby Sessions (Location Gate) ─────────────────── --}}
    <section id="nearby-sessions-section" class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-10">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __("discovery.content_what_s_happening_near_you") }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('games.content_share_your_location_to_see') }}
                </p>
            </div>
            <div id="nearby-sessions" class="min-h-[420px]">
                @livewire('components.nearby-sessions', ['radius' => 10, 'limit' => 4])
            </div>
        </div>
    </section>

    {{-- ── Everything in One Place (Directory Strip) ───────────
         M066/S09/T02: surface the real public directories early so the
         flagship surfaces (catalog, GM directory, venues, city hubs) are
         visible without scrolling to the footer. --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.content_home_directories_heading') }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('pages.content_home_directories_intro') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                {{-- Game System Catalog --}}
                <a href="{{ route('game-systems') }}" wire:navigate class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-shadow text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">board_games</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2 group-hover:text-primary transition-colors">{{ __('pages.content_home_directory_systems_title') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_home_directory_systems_text') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-primary">{{ __('pages.action_browse_game_systems') }} →</span>
                </a>

                {{-- GM Directory --}}
                <a href="{{ route('gm.directory') }}" wire:navigate class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-shadow text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">psychology</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2 group-hover:text-primary transition-colors">{{ __('pages.content_home_directory_gms_title') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_home_directory_gms_text') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-primary">{{ __('pages.action_browse_gm_directory') }} →</span>
                </a>

                {{-- Venue Directory --}}
                <a href="{{ route('venues.directory') }}" wire:navigate class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-shadow text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">storefront</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2 group-hover:text-primary transition-colors">{{ __('pages.content_home_directory_venues_title') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_home_directory_venues_text') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-primary">{{ __('pages.action_browse_venues') }} →</span>
                </a>

                {{-- City Hubs --}}
                <a href="{{ route('city-hubs.index') }}" wire:navigate class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-shadow text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">location_city</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2 group-hover:text-primary transition-colors">{{ __('pages.content_home_directory_cities_title') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_home_directory_cities_text') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-primary">{{ __('pages.action_browse_city_hubs') }} →</span>
                </a>
            </div>
        </div>
    </section>

    {{-- ── Living Stats ────────────────────────────────────── --}}
    <section class="bg-inverse-surface text-inverse-on-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 text-center">
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $sessionsThisWeek }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                    {{ trans_choice('campaigns.content_sessions_this_week', $sessionsThisWeek) }}
                    </div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $peopleThisWeek }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                        {{ trans_choice('campaigns.content_people_joined_sessions_this_week', $peopleThisWeek) }}
                    </div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $activeCampaigns }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                        {{ trans_choice('campaigns.content_active_campaigns', $activeCampaigns) }}
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Trust Badge Row ─────────────────────────────────── --}}
    <section class="bg-inverse-surface text-inverse-on-surface border-t border-inverse-on-surface/10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6">
                {{-- In-Person by Design --}}
                <a href="{{ route('about') }}#why-local" class="group flex items-start gap-3 p-4 rounded-lg bg-inverse-on-surface/5 hover:bg-inverse-on-surface/10 transition-colors">
                    <span class="material-symbols-outlined text-inverse-primary text-xl mt-0.5 shrink-0" aria-hidden="true">groups</span>
                    <div>
                        <div class="font-semibold text-sm text-inverse-on-surface group-hover:text-inverse-primary transition-colors">{{ __('pages.content_trust_badge_in_person_title') }}</div>
                        <div class="text-xs text-inverse-on-surface/60 mt-0.5">{{ __('pages.content_trust_badge_in_person_text') }}</div>
                    </div>
                </a>

                {{-- Non-profit by Design --}}
                <a href="{{ route('pledge') }}" wire:navigate class="group flex items-start gap-3 p-4 rounded-lg bg-inverse-on-surface/5 hover:bg-inverse-on-surface/10 transition-colors">
                    <span class="material-symbols-outlined text-inverse-primary text-xl mt-0.5 shrink-0" aria-hidden="true">volunteer_activism</span>
                    <div>
                        <div class="font-semibold text-sm text-inverse-on-surface group-hover:text-inverse-primary transition-colors">{{ __('pages.content_pledge_nonprofit_heading') }}</div>
                        <div class="text-xs text-inverse-on-surface/60 mt-0.5">{{ __('pages.content_trust_badge_nonprofit_text') }}</div>
                    </div>
                </a>

                {{-- Open Source on GitHub --}}
                <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener noreferrer" class="group flex items-start gap-3 p-4 rounded-lg bg-inverse-on-surface/5 hover:bg-inverse-on-surface/10 transition-colors">
                    <span class="material-symbols-outlined text-inverse-primary text-xl mt-0.5 shrink-0" aria-hidden="true">code</span>
                    <div>
                        <div class="font-semibold text-sm text-inverse-on-surface group-hover:text-inverse-primary transition-colors">{{ __('pages.content_trust_badge_opensource_title') }}</div>
                        <div class="text-xs text-inverse-on-surface/60 mt-0.5">{{ __('pages.content_trust_badge_opensource_text') }}</div>
                    </div>
                </a>

                {{-- Safety Built In --}}
                <a href="{{ route('safety-tools') }}" wire:navigate class="group flex items-start gap-3 p-4 rounded-lg bg-inverse-on-surface/5 hover:bg-inverse-on-surface/10 transition-colors">
                    <span class="material-symbols-outlined text-inverse-primary text-xl mt-0.5 shrink-0" aria-hidden="true">shield_person</span>
                    <div>
                        <div class="font-semibold text-sm text-inverse-on-surface group-hover:text-inverse-primary transition-colors">{{ __('pages.content_trust_badge_safety_title') }}</div>
                        <div class="text-xs text-inverse-on-surface/60 mt-0.5">{{ __('pages.content_trust_badge_safety_text') }}</div>
                    </div>
                </a>
            </div>
        </div>
    </section>

    {{-- ── Values Strip ────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('pages.content_built_for_real_connection') }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('common.content_tabletop_gaming_is_about_more') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                {{-- Welcoming Community --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">diversity_3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('common.field_welcoming_community') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_every_table_has_room_for') }}</p>
                </div>

                {{-- Imaginative Play --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">auto_awesome</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('common.content_imaginative_play') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('campaigns.content_from_epic_campaigns_to_quick') }}</p>
                </div>

                {{-- Safe Spaces --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('common.content_safe_spaces') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('pages.content_safe_spaces_trust') }}</p>
                    <a href="{{ route('pledge') }}" wire:navigate class="inline-block mt-3 text-sm font-medium text-primary hover:underline">{{ __('pages.content_safe_spaces_pledge_link') }} →</a>
                </div>

                {{-- Discovery --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">explore</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('discovery.content_discovery') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('games.content_step_outside_your_comfort_zone') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA (player audience — shared component) ─────────── --}}
    <x-cta-block variant="player" />
</x-public-layout>
