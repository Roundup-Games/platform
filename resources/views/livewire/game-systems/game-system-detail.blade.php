<div>
    @section('title', $system->name)

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
        @php($coverUrl = $system->getFirstMediaUrl('cover'))
        @if(!$coverUrl && $system->thumbnail_url)
            @php($coverUrl = $system->thumbnail_url)
        @endif
        @if($coverUrl)
            <div class="absolute inset-0">
                <img src="{{ $coverUrl }}" alt="" class="w-full h-full object-cover opacity-[0.08]" aria-hidden="true">
                <div class="absolute inset-0 bg-gradient-to-b from-primary/40 via-primary to-primary"></div>
            </div>
        @endif

        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14 text-center">
            {{-- Small centered thumbnail --}}
            <div class="mx-auto w-20 h-20 sm:w-24 sm:h-24 rounded-xl overflow-hidden shadow-lg bg-on-primary/10 mb-5">
                @if($coverUrl)
                    <img src="{{ $coverUrl }}" alt="{{ $system->name }}" class="w-full h-full object-cover">
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

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-8">

        {{-- Flash message --}}
        @if(session()->has('status'))
            <div role="status" aria-live="polite" class="px-4 py-3 rounded-lg bg-primary/10 text-primary text-sm font-medium">
                {{ session('status') }}
            </div>
        @endif

        {{-- ── Preference & Discovery Bar ──────────────────────── --}}
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 bg-surface-container rounded-xl shadow-ambient">
            {{-- User preference toggle --}}
            <div class="flex items-center gap-3">
                @auth
                    <button wire:click="toggleFavorite"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors duration-150 {{ $this->userPreference === 'favorite' ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant hover:bg-primary/10 hover:text-primary' }}">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">{{ $this->userPreference === 'favorite' ? 'favorite' : 'favorite_border' }}</span>
                        {{ $this->userPreference === 'favorite' ? __('games.action_remove_from_favorites') : __('games.action_add_to_favorites') }}
                    </button>
                    <button wire:click="toggleAvoid"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors duration-150 {{ $this->userPreference === 'avoid' ? 'bg-error text-on-error' : 'bg-surface-container-high text-on-surface-variant hover:bg-error/10 hover:text-error' }}">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">{{ $this->userPreference === 'avoid' ? 'block' : 'block' }}</span>
                        {{ $this->userPreference === 'avoid' ? __('games.action_remove_from_avoid_list') : __('games.action_add_to_avoid_list') }}
                    </button>
                @else
                    <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person_add</span>
                        {{ __('games.guest_nudge_game_systems') }}
                    </a>
                @endauth
            </div>

            {{-- Community stats --}}
            <div class="flex items-center gap-4 text-sm text-on-surface-variant">
                @if($this->favoritedCount > 0)
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">favorite</span>
                        {{ __('games.content_users_favorite_this', ['count' => $this->favoritedCount]) }}
                    </span>
                @endif
                @if($this->avoidedCount > 0)
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base text-error" aria-hidden="true">block</span>
                        {{ __('games.content_users_avoid_this', ['count' => $this->avoidedCount]) }}
                    </span>
                @endif
            </div>
        </div>

        {{-- ── Two-column layout: Description + Metadata ────────── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Description (2/3 width) --}}
            <div class="lg:col-span-2 space-y-6">
                @if($system->description)
                    <section>
                        <h2 class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('games.heading_about_this_game') }}</h2>
                        <div class="prose prose-sm max-w-none text-on-surface-variant prose-headings:text-on-surface prose-a:text-primary">
                            {!! $system->description !!}
                        </div>
                    </section>
                @endif

                {{-- Categories & Mechanics tags --}}
                @if($system->categories->count() || $system->mechanics->count())
                    <section class="space-y-4">
                        @if($system->categories->count())
                            <div>
                                <h3 class="text-sm font-heading font-bold text-on-surface mb-2">{{ __('games.heading_categories') }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($system->categories as $cat)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                            {{ $cat->translatedName() }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if($system->mechanics->count())
                            <div>
                                <h3 class="text-sm font-heading font-bold text-on-surface mb-2">{{ __('games.heading_mechanics') }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($system->mechanics as $mech)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                            {{ $mech->translatedName() }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            </div>

            {{-- Metadata sidebar (1/3 width) --}}
            <aside>
                <div class="bg-surface-container rounded-xl shadow-ambient p-5 space-y-4 sticky top-24">
                    <h2 class="text-sm font-heading font-bold text-primary uppercase tracking-wide">{{ __('games.heading_game_details') }}</h2>

                    <dl class="space-y-3 text-sm">
                        @if($system->min_players || $system->max_players)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">group</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.field_player_count') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }}</dd>
                                </div>
                            </div>
                        @endif
                        @if($system->average_play_time)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">schedule</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_play_time') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ $system->average_play_time }} {{ strtolower(__('games.content_min')) }}</dd>
                                </div>
                            </div>
                        @endif
                        @if($system->age_rating)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">person</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_age') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ $system->age_rating }}+</dd>
                                </div>
                            </div>
                        @endif
                        @if($system->year_released)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">calendar_today</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_year') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ $system->year_released }}</dd>
                                </div>
                            </div>
                        @endif
                        @if($system->bgg_average_rating)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-amber-500 mt-0.5" aria-hidden="true">star</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_bgg_rating') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ number_format($system->bgg_average_rating, 1) }} / 10
                                        @if($system->bgg_users_rated)
                                            <span class="text-on-surface-variant text-xs">({{ number_format($system->bgg_users_rated) }})</span>
                                        @endif
                                    </dd>
                                </div>
                            </div>
                        @endif
                        @if($system->bgg_average_weight && $system->bgg_average_weight > 0)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">fitness_center</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_complexity') }}</dt>
                                    <dd class="font-medium text-on-surface">{{ number_format($system->bgg_average_weight, 1) }} / 5</dd>
                                    <div class="mt-1 h-1.5 bg-surface-container-highest rounded-full overflow-hidden max-w-[120px]">
                                        <div class="h-full rounded-full bg-gradient-to-r from-green-400 via-amber-400 to-red-400" style="width: {{ min(100, ($system->bgg_average_weight / 5) * 100) }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        @if($system->bgg_rank)
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5" aria-hidden="true">emoji_events</span>
                                <div>
                                    <dt class="text-on-surface-variant">{{ __('games.content_boardgamegeek_rank_rank', ['rank' => '']) }}</dt>
                                    <dd class="font-medium text-on-surface">#{{ number_format($system->bgg_rank) }}</dd>
                                </div>
                            </div>
                        @endif
                    </dl>
                </div>
            </aside>
        </div>

        {{-- ── Base Game Quick Info (for expansions) ──────────────── --}}
        @if($system->baseGame)
            <section class="bg-surface-container rounded-xl shadow-ambient p-5">
                <h2 class="text-sm font-heading font-bold text-primary uppercase tracking-wide mb-3">{{ __('games.content_base_game_quick_info') }}</h2>
                <a href="{{ route('game-systems.show', $system->baseGame->slug) }}" wire:navigate class="flex items-center gap-4 p-3 bg-surface rounded-lg hover:bg-primary/5 transition-colors group">
                    <div class="shrink-0 w-16 h-16 rounded-lg overflow-hidden bg-surface-container-high">
                        @php($baseCover = $system->baseGame->getFirstMediaUrl('cover', 'thumb'))
                        @if($baseCover)
                            <img src="{{ $baseCover }}" alt="{{ $system->baseGame->name }}" class="w-full h-full object-cover">
                        @elseif($system->baseGame->thumbnail_url)
                            <img src="{{ $system->baseGame->thumbnail_url }}" alt="{{ $system->baseGame->name }}" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">casino</span>
                            </div>
                        @endif
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ $system->baseGame->name }}</h3>
                        @if($system->baseGame->bgg_average_rating)
                            <p class="text-sm text-on-surface-variant flex items-center gap-1 mt-1">
                                <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">star</span>
                                {{ number_format($system->baseGame->bgg_average_rating, 1) }}
                                @if($system->baseGame->bgg_rank)
                                    <span class="ml-2">Rank #{{ number_format($system->baseGame->bgg_rank) }}</span>
                                @endif
                            </p>
                        @endif
                    </div>
                    <span class="material-symbols-outlined text-on-surface-variant ml-auto group-hover:text-primary transition-colors" aria-hidden="true">arrow_forward</span>
                </a>
            </section>
        @endif

        {{-- ── Expansions Section (for base games) ────────────────── --}}
        @if($system->expansions->count() > 0)
            <section>
                <h2 class="text-lg font-heading font-bold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">extension</span>
                    {{ __('games.heading_expansions') }}
                    <span class="text-sm font-normal text-on-surface-variant">({{ $system->expansions->count() }})</span>
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                    @foreach($system->expansions as $expansion)
                        <a href="{{ route('game-systems.show', $expansion->slug) }}" wire:navigate class="block bg-surface-container rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                            <div class="aspect-square bg-surface-container-high relative overflow-hidden">
                                @php($expCover = $expansion->getFirstMediaUrl('cover', 'thumb'))
                                @if($expCover)
                                    <img src="{{ $expCover }}" alt="{{ $expansion->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                @elseif($expansion->thumbnail_url)
                                    <img src="{{ $expansion->thumbnail_url }}" alt="{{ $expansion->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-on-surface-variant/30" aria-hidden="true">extension</span>
                                    </div>
                                @endif
                                @if($expansion->bgg_average_rating)
                                    <span class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-on-primary/80 text-primary backdrop-blur-sm">
                                        {{ number_format($expansion->bgg_average_rating, 1) }} ★
                                    </span>
                                @endif
                            </div>
                            <div class="p-2.5">
                                <h3 class="text-xs font-medium text-on-surface leading-tight line-clamp-2 group-hover:text-primary transition-colors">{{ $expansion->name }}</h3>
                                @if($expansion->year_released)
                                    <p class="text-[10px] text-on-surface-variant mt-1">{{ $expansion->year_released }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Active Sessions & Campaigns Discovery ────────────────── --}}
        <section class="bg-surface-container rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-bold text-on-surface mb-2 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">explore</span>
                {{ __('games.heading_game_sessions') }}
            </h2>
            @php($sessionCount = $system->active_sessions_count)
            @php($campaignCount = $system->active_campaigns_count)
            @if($sessionCount > 0 || $campaignCount > 0)
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('games.content_sessions_using_this_system', ['sessions' => $sessionCount, 'campaigns' => $campaignCount]) }}
                </p>
                <div class="flex flex-wrap gap-3">
                    @if($sessionCount > 0)
                        <a href="{{ route('discover', ['game_system_id' => $system->id, 'mode' => 'games']) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">group</span>
                            {{ $sessionCount }} {{ __('games.content_active_sessions', ['count' => $sessionCount]) }}
                        </a>
                    @endif
                    @if($campaignCount > 0)
                        <a href="{{ route('discover', ['game_system_id' => $system->id, 'mode' => 'campaigns']) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-secondary-container text-on-secondary-container rounded-xl text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">campaign</span>
                            {{ $campaignCount }} {{ __('games.content_active_campaigns', ['count' => $campaignCount]) }}
                        </a>
                    @endif
                </div>
            @else
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('games.content_no_game_systems_available_yet_short') }}
                </p>
                <a href="{{ route('discover') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-surface-container-high text-on-surface-variant rounded-xl text-sm font-medium hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">explore</span>
                    {{ __('games.action_find_sessions') }}
                </a>
            @endif
        </section>
    </div>
</div>
