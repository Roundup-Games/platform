<div>
    {{-- ── Hero Section ────────────────────────────────────────── --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-4xl mx-auto px-4 py-16 text-center">
            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ __('discovery.field_what_are_you_in_the_mood_for') }}</h1>
            <p class="mt-3 text-base text-on-primary/80">{{ __('discovery.content_choose_your_adventure') }}</p>
        </div>
    </section>

    {{-- ── Track Cards ──────────────────────────────────────────── --}}
    <div class="max-w-4xl mx-auto px-4 py-8 grid grid-cols-1 sm:grid-cols-2 gap-6">
        {{-- Board Game Night card --}}
        <a href="{{ route('discover.board-games', ['locale' => app()->getLocale()]) }}" wire:navigate
           class="block bg-surface rounded-2xl shadow-ambient p-8 hover:shadow-md transition-shadow group">
            <span class="material-symbols-outlined text-4xl text-primary" aria-hidden="true">casino</span>
            <h2 class="mt-4 text-xl font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('discovery.field_board_game_night') }}</h2>
            <p class="mt-2 text-sm text-on-surface-variant">{{ __('discovery.content_find_sessions_near_you') }}</p>
            @if($boardGameCount > 0)
                <span class="inline-flex items-center gap-1 mt-4 px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ $boardGameCount }} {{ __('common.field_upcoming') }}
                </span>
            @endif
        </a>

        {{-- Tabletop Adventures card --}}
        <a href="{{ route('discover.adventures', ['locale' => app()->getLocale()]) }}" wire:navigate
           class="block bg-surface rounded-2xl shadow-ambient p-8 hover:shadow-md transition-shadow group">
            <span class="material-symbols-outlined text-4xl text-secondary" aria-hidden="true">swords</span>
            <h2 class="mt-4 text-xl font-heading font-semibold text-on-surface group-hover:text-secondary transition-colors">{{ __('discovery.field_tabletop_adventures') }}</h2>
            <p class="mt-2 text-sm text-on-surface-variant">{{ __('discovery.content_join_campaigns_oneshots') }}</p>
            @if($adventureCount > 0)
                <span class="inline-flex items-center gap-1 mt-4 px-3 py-1 rounded-full text-xs font-medium bg-secondary/10 text-on-secondary-container">
                    {{ $adventureCount }} {{ __('discovery.content_looking_for_players') }}
                </span>
            @endif
        </a>
    </div>

    {{-- Venue directory cross-link: a slim banner under the two track cards.
         Local discovery runs through venues, so the directory is one hop from
         the portal without crowding the 2-card grid above. --}}
    <div class="max-w-4xl mx-auto px-4 pb-12">
        <a href="{{ route('venues.directory', ['locale' => app()->getLocale()]) }}" wire:navigate
           class="flex items-center justify-between gap-4 bg-surface-container-low border border-outline-variant/15 rounded-2xl p-5 hover:border-primary/40 hover:shadow-md transition-all group">
            <div class="flex items-center gap-4 min-w-0">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-primary-container text-on-primary-container shrink-0">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">storefront</span>
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('venue.heading_directory_portal_card') }}</h2>
                    <p class="text-sm text-on-surface-variant truncate">{{ __('venue.content_directory_portal_card') }}</p>
                </div>
            </div>
            <span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary transition-colors shrink-0" aria-hidden="true">arrow_forward</span>
        </a>
    </div>
</div>
