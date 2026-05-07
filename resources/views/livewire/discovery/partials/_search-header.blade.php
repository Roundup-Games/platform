{{-- ── Compact Header ────────────────────────────────────────── --}}
<section class="bg-primary text-on-primary">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
        <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('games.action_discover_games_campaigns') }}</h1>
        <p class="mt-1 text-sm text-on-primary/80">{{ __('discovery.action_find_games_and_campaigns_that_match_your_vibe') }}</p>

        {{-- ── Search ─────────────────────────────────────── --}}
        <div class="mt-4 relative max-w-xl">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-primary/60 text-lg" aria-hidden="true">search</span>
            <input type="text" aria-label="{{ __('discovery.action_search') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('games.action_search_games_and_campaigns') }}"
                   class="w-full pl-10 pr-4 py-2.5 bg-on-primary/10 border border-on-primary/20 rounded-full text-on-primary placeholder:text-on-primary/50 focus:bg-on-primary/20 focus:border-on-primary/40 focus:ring-2 focus:ring-on-primary/20" />
        </div>
    </div>
</section>
