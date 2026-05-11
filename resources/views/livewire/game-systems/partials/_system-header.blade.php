    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('game-systems') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('games.action_back_to_game_systems') }}
            </a>
        </div>
    </div>

    {{-- ── Hero Section ──────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        {{-- Background mood image — very low opacity --}}
        @php($coverUrl = $system->coverImageUrl())
        @if($coverUrl)
            <div class="absolute inset-0">
                <img src="{{ $coverUrl }}" alt="" class="w-full h-full object-cover opacity-95 blur-sm scale-105" aria-hidden="true" data-fallback="hide">
            </div>
            <div class="absolute inset-0 bg-gradient-to-b from-primary/85 via-primary/95 to-primary"></div>
        @endif

        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14 text-center">
            {{-- Small centered thumbnail --}}
            <div class="mx-auto w-32 h-32 sm:w-48 sm:h-48 rounded-xl overflow-hidden shadow-lg bg-on-primary/10 mb-5">
                @if($coverUrl)
                    <img src="{{ $coverUrl }}" alt="{{ $system->name }}" class="w-full h-full object-cover" data-fallback="hide">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <span class="material-symbols-outlined text-4xl text-on-primary/40" aria-hidden="true">casino</span>
                    </div>
                @endif
            </div>

            {{-- Badges --}}
            <div class="flex flex-wrap items-center justify-center gap-2 mb-3">
                @if($system->bgg_rank)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-on-primary/20 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">emoji_events</span>
                        #{{ number_format($system->bgg_rank) }}
                    </span>
                @endif
                @if($system->baseGame)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                        {{ __('games.content_expansion_of_name', ['name' => $system->baseGame->name]) }}
                    </span>
                @endif
                @if($system->expansions->count() > 0)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">extension</span>
                        {{ __('games.content_expansion_count_badge', ['count' => $system->expansions->count()]) }}
                    </span>
                @endif
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $system->name }}</h1>

            {{-- Quick stats --}}
            <div class="mt-4 flex flex-wrap justify-center gap-4 text-sm text-on-primary/80">
                @if($system->min_players || $system->max_players)
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">group</span>
                        {{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }} {{ __('common.content_players') }}
                    </span>
                @endif
                @if($system->average_play_time)
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">schedule</span>
                        {{ $system->average_play_time }} {{ strtolower(__('games.content_min')) }}
                    </span>
                @endif
                @if($system->year_released)
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                        {{ $system->year_released }}
                    </span>
                @endif
                @if($system->age_rating)
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person</span>
                        {{ $system->age_rating }}+
                    </span>
                @endif
                @if($system->bgg_average_rating)
                    <span class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-lg text-amber-300" aria-hidden="true">star</span>
                        {{ number_format($system->bgg_average_rating, 1) }}
                        @if($system->bgg_users_rated)
                            <span class="text-on-primary/50 text-xs">({{ number_format($system->bgg_users_rated) }})</span>
                        @endif
                    </span>
                @endif
            </div>

            {{-- Complexity bar --}}
            @if($system->bgg_average_weight && $system->bgg_average_weight > 0)
                <div class="mt-4 flex items-center justify-center gap-3 max-w-xs mx-auto">
                    <span class="text-xs text-on-primary/60">{{ __('games.content_weight_light') }}</span>
                    <div class="flex-1 h-2 bg-on-primary/15 rounded-full overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-green-300 via-amber-300 to-red-300" style="width: {{ min(100, ($system->bgg_average_weight / 5) * 100) }}%"></div>
                    </div>
                    <span class="text-xs text-on-primary/60">{{ __('games.content_weight_heavy') }}</span>
                    <span class="text-xs font-semibold text-on-primary">{{ number_format($system->bgg_average_weight, 1) }}/5</span>
                </div>
            @endif
        </div>
    </section>
