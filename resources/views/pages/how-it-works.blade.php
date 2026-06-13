<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('events.content_how_roundup_works') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('pages.content_we_believe_everyone_deserves_a') }}
            </p>
        </div>
    </section>

    {{-- ── 3-Step Visual Section ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-16">
                {{ __('games.content_three_steps_to_your_next_game_night') }}
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 md:gap-8">
                {{-- Step 1: Discover --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">explore</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">1</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('campaigns.action_discover_sessions_near_you') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('campaigns.content_share_your_location_and_browse') }}
                    </p>
                </div>

                {{-- Step 2: Find --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">casino</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">2</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('games.action_find_your_kind_of_game') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('games.content_love_epic_rpg_campaigns_quick') }}
                    </p>
                </div>

                {{-- Step 3: Show Up --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">groups</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('common.content_show_up_and_play') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('auth.content_sign_up_for_a_session') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Safety & Vetting Section ─────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto">
                <div class="text-center mb-10">
                    <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">verified_user</span>
                    <h2 class="mt-4 text-3xl font-heading font-bold tracking-tight text-on-surface">
                        {{ __('safety.content_your_safety_built_in') }}
                    </h2>
                    <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                        {{ __('pages.content_we_take_trust_seriously_here') }}
                    </p>
                </div>
                <div class="space-y-6">
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 shrink-0" aria-hidden="true">visibility</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('campaigns.content_transparent_sessions') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('campaigns.content_every_public_session_is_visible') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 shrink-0" aria-hidden="true">person</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('profile.content_organizer_profiles') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('pages.content_organizer_profiles_are_public_you') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 shrink-0" aria-hidden="true">group</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('campaigns.content_protected_sessions') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('campaigns.content_protected_sessions_require_membership_approval') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 shrink-0" aria-hidden="true">forum</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('safety.content_session_zero_support') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('safety.content_for_rpg_campaigns_we_encourage') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Bridge to in-person trust (M043 S04 T02) --}}
                <div class="mt-10 pt-8 border-t border-outline-variant">
                    <p class="text-on-surface-variant leading-relaxed max-w-2xl mx-auto">
                        {{ __('pages.content_safety_bridge_trust') }}
                    </p>
                    <p class="mt-4 text-on-surface-variant leading-relaxed max-w-2xl mx-auto">
                        {{ __('pages.content_safety_bridge_nonprofit') }}
                    </p>
                    <p class="mt-4">
                        <a href="{{ route('pledge') }}" wire:navigate class="text-primary font-semibold hover:underline">
                            {{ __('pages.action_learn_about_our_pledge') }}
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA Section ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('common.content_ready_to_find_your_table') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('campaigns.content_create_a_free_account_set') }}
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
                        {{ __('campaigns.action_browse_sessions') }}
                    </a>
                @else
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('campaigns.action_browse_sessions') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>
