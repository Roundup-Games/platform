{{-- ── Empty State ────────────────────────────────────────── --}}
<div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
    <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">explore</span>
    <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('common.content_no_results_found') }}</h3>
    <p class="mt-1 text-sm text-on-surface-variant">
        @if($activeFilters)
            {{ __('common.action_try_adjusting_your_filters') }}
        @else
            {{ __('games.content_check_back_soon_for_new_games_and_campaigns') }}
        @endif
    </p>
</div>
