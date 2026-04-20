<x-public-layout>
@section('title', __('common.content_for_organizers'))

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('games.content_bring_your_games_to_the_table') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('games.content_you_ve_got_the_game') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('pages.field_start_hosting_it_s_free') }}
                    </a>
                @else
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                        {{ __('campaigns.action_create_your_first_session') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>

    {{-- ── Benefit Cards ────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-4">
                {{ __('campaigns.content_everything_you_need_to_run_great_sessions') }}
            </h2>
            <p class="text-on-surface-variant text-center max-w-2xl mx-auto mb-12">
                {{ __('campaigns.content_no_more_group_chats_spreadsheets') }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- One link for signups --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">link</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('common.field_one_link_for_signups') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('campaigns.content_share_a_single_link_for') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Automatic player matching --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">group</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('events.content_automatic_player_matching') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('campaigns.content_your_sessions_appear_in_discovery') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Campaign management --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">map</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('campaigns.content_campaign_management') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('games.content_running_a_multi_session_campaign') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Visibility controls --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">tune</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('common.content_visibility_controls') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('campaigns.content_make_sessions_public_for_anyone') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── How It Works: 3 Steps ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-16">
                {{ __('games.content_from_idea_to_game_night_in_three_steps') }}
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 md:gap-8">
                {{-- Step 1: Create --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">add_circle</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">1</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('campaigns.action_create_a_session') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('games.content_pick_your_game_set_the') }}
                    </p>
                </div>

                {{-- Step 2: Preferences --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">tune</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">2</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('profile.action_set_your_preferences') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('games.content_choose_your_visibility_level_add') }}
                    </p>
                </div>

                {{-- Step 3: Players Find You --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">group_add</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('common.content_players_find_you') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('campaigns.content_your_session_appears_in_discovery') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Social Proof ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-inverse-surface text-inverse-on-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <div class="max-w-3xl mx-auto">
                <span class="material-symbols-outlined text-inverse-primary text-5xl mb-4 block" aria-hidden="true">groups</span>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                    {{ trans_choice('common.action_join_count_organizers_who_bring_people_together', $displayCount) }}
                </h2>
                <p class="mt-4 text-inverse-on-surface/70 max-w-xl mx-auto text-lg">
                    {{ __('events.content_from_weekly_board_game_nights') }}
                </p>
            </div>
        </div>
    </section>

    {{-- ── CTA Section ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('campaigns.field_start_your_first_session_it_s_free') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('pages.content_no_subscriptions_required_no_credit') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('auth.content_sign_up_free') }}
                    </a>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('pages.content_see_how_it_works') }}
                    </a>
                @else
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                        {{ __('campaigns.action_create_your_first_session') }}
                    </a>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('campaigns.action_browse_sessions') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>
