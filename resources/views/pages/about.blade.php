<x-public-layout>
@section('title', __('pages.content_about'))

    <x-hero :title="__('pages.content_about_roundup_games')" :subtitle="__('events.content_building_community_through_competitive_gaming')" />

    {{-- Mission Section --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto">
                <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('pages.content_our_mission') }}</h2>
                <div class="mt-6 space-y-4 text-on-surface-variant text-base leading-relaxed">
                    <p>
                        {{ __('events.content_roundup_games_was_born_from') }}
                    </p>
                    <p>
                        {{ __('events.content_our_platform_makes_it_easy') }}
                    </p>
                    <p>
                        {{ __("events.content_we_believe_that_everyone_should") }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Values Section — editorial shadows, Material Symbols --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-12">{{ __('pages.content_what_we_stand_for') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">groups</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('pages.content_community_first') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('events.content_everything_we_build_starts_with') }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">shield</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('common.content_fair_play') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("discovery.content_we_re_committed_to_creating") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">bolt</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{!! __('common.content_simple_fast') !!}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("pages.content_from_creating_an_event_to") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">public</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('common.action_open_to_all') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("events.content_whether_you_re_a_seasoned") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">assignment</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('common.content_organizer_empowerment') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('events.content_we_give_organizers_the_tools') }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">favorite</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('games.content_passion_for_games') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("games.content_we_re_gamers_ourselves_that") }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Team Section --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.content_our_team') }}</h2>
                <p class="mt-4 text-on-surface-variant text-base leading-relaxed">
                    {{ __("pages.content_we_re_a_small_team") }}
                </p>
            </div>
        </div>
    </section>

    {{-- Community CTA — warm amber gradient --}}
    <section class="py-16 sm:py-20 bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl font-heading font-bold tracking-tight">{{ __('common.action_join_our_community') }}</h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __("events.content_whether_you_want_to_organize") }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                    {{ __('events.action_browse_events') }}
                </a>
                <a href="{{ route('contact') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                    {{ __('pages.action_get_in_touch') }}
                </a>
            </div>
        </div>
    </section>
</x-public-layout>
