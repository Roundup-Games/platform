@props([
    'nearbyNoteworthy' => [],
])

@php
    $hasGames = !empty($nearbyNoteworthy);
    $tagStyles = [
        'matches_your_taste' => 'bg-primary/10 text-primary',
        'popular_nearby' => 'bg-secondary/10 text-secondary',
        'filling_fast' => 'bg-error/10 text-error',
        'starting_soon' => 'bg-tertiary/10 text-tertiary',
        'friends_are_going' => 'bg-primary/10 text-primary',
    ];
    $tagLabels = [
        'matches_your_taste' => 'profile.dashboard_nearby_matches_taste',
        'popular_nearby' => 'profile.dashboard_nearby_popular',
        'filling_fast' => 'profile.dashboard_nearby_filling_fast',
        'starting_soon' => 'profile.dashboard_nearby_starting_soon',
        'friends_are_going' => 'profile.dashboard_nearby_friends_going',
    ];
@endphp

<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">explore</span>
        {{ __('profile.dashboard_nearby_heading') }}
    </h3>

    @if($hasGames)
        {{-- Horizontal scrollable card row --}}
        <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory" role="list" aria-label="{{ __('profile.dashboard_nearby_heading') }}">
            @foreach($nearbyNoteworthy as $game)
                @php
                    $systemBadge = $game['system_badge'] ?? [];
                    $relevanceTags = $game['relevance_tags'] ?? [];
                    $spotsAvailable = $game['spots_available'] ?? null;
                    $distanceKm = $game['distance_km'] ?? null;
                    $dateTime = isset($game['date_time']) ? \Carbon\Carbon::parse($game['date_time']) : null;
                    $dateText = $dateTime ? format_date($dateTime, 'short_date') : null;
                @endphp
                <a href="{{ route('games.show', $game['id']) }}" wire:navigate
                   class="flex-shrink-0 w-56 sm:w-64 snap-start bg-surface-container-low rounded-xl border border-outline-variant/30 hover:border-primary/40 hover:shadow-ambient-md transition-all p-4 group"
                   role="listitem">
                    <div class="min-w-0">
                        {{-- System badge --}}
                        @if($systemBadge['name'] ?? null)
                            <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary mb-2">
                                {{ $systemBadge['name'] }}
                            </span>
                        @endif

                        {{-- Game name --}}
                        <p class="text-sm font-semibold text-on-surface group-hover:text-primary transition-colors truncate leading-tight">
                            {{ $game['name'] }}
                        </p>

                        {{-- Date/time --}}
                        @if($dateText)
                            <p class="text-xs text-on-surface-variant mt-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                {{ $game['relative_time'] ?? $dateText }}
                            </p>
                        @endif

                        {{-- Spots + distance row --}}
                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                            @if($spotsAvailable !== null)
                                <span class="text-[11px] text-on-surface-variant flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                                    {{ trans_choice('profile.dashboard_nearby_spots', $spotsAvailable) }}
                                </span>
                            @endif
                            @if($distanceKm !== null)
                                <span class="text-[11px] text-on-surface-variant flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                                    {{ $distanceKm }} {{ __('common.unit_km') }}
                                </span>
                            @endif
                        </div>

                        {{-- Relevance tag pills (max 2) --}}
                        @if(!empty($relevanceTags))
                            <div class="flex flex-wrap gap-1 mt-2">
                                @foreach(array_slice($relevanceTags, 0, 2) as $tag)
                                    @if(isset($tagStyles[$tag]))
                                        <span class="inline-block text-[9px] font-semibold px-1.5 py-0.5 rounded-full {{ $tagStyles[$tag] }}">
                                            {{ __($tagLabels[$tag]) }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @else
        {{-- Empty state --}}
        <div class="text-center py-8">
            <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-4xl mb-2 block" style="font-variation-settings: 'FILL' 0">explore_off</span>
            <p class="text-on-surface-variant text-sm mb-3">{{ __('profile.dashboard_nearby_empty') }}</p>
            @can('create', \App\Models\Game::class)
                <a href="{{ route('games.create') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                    <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">add_circle</span>
                    {{ __('profile.dashboard_newcomer_be_first_cta') }}
                </a>
            @else
                <a href="{{ route('profile.edit') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                    <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">edit_location</span>
                    {{ __('profile.dashboard_nearby_set_location') }}
                </a>
            @endcan
        </div>
    @endif
</div>
