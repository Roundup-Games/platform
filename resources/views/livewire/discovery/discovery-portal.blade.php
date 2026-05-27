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
</div>
