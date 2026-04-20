<div>
    <x-hero :title="__('games.action_explore_game_systems')" :subtitle="__('games.content_game_systems_knowledge_base_subtitle')" />

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 space-y-6">

        {{-- ── Search ────────────────────────────────────────────────── --}}
        <div class="max-w-2xl mx-auto">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant text-xl" aria-hidden="true">search</span>
                <input type="text"
                       aria-label="{{ __('games.action_search_game_systems') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('games.action_search_game_systems') }}"
                       class="w-full pl-12 pr-4 py-3 bg-surface-container-high border border-transparent rounded-full text-on-surface text-sm placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
        </div>

        {{-- ── Filter Rows ────────────────────────────────────────────── --}}
        <div class="space-y-4">

            {{-- Categories --}}
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <h3 class="text-xs font-bold text-primary uppercase tracking-wide">{{ __('games.heading_categories') }}</h3>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($visibleCategories as $category)
                        @php($active = in_array($category->id, $category_ids))
                        <button wire:click="toggleCategory({{ $category->id }})"
                                class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors duration-150 {{ $active ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-high text-on-surface-variant hover:bg-primary/10 hover:text-primary' }}">
                            {{ $category->translatedName() }}
                        </button>
                    @endforeach
                    @if($allCategories->count() > 12)
                        <button wire:click="$toggle('showAllCategories')" class="px-2.5 py-1 rounded-full text-xs font-medium text-primary hover:bg-primary/10 transition-colors">
                            {{ $showAllCategories ? __('games.action_show_less_categories') : __('games.action_show_more_categories') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Mechanics --}}
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <h3 class="text-xs font-bold text-primary uppercase tracking-wide">{{ __('games.heading_mechanics') }}</h3>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($visibleMechanics as $mechanic)
                        @php($active = in_array($mechanic->id, $mechanic_ids))
                        <button wire:click="toggleMechanic({{ $mechanic->id }})"
                                class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors duration-150 {{ $active ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'bg-surface-container-high text-on-surface-variant hover:bg-secondary-container/50 hover:text-on-secondary-container' }}">
                            {{ $mechanic->translatedName() }}
                        </button>
                    @endforeach
                    @if($allMechanics->count() > 12)
                        <button wire:click="$toggle('showAllMechanics')" class="px-2.5 py-1 rounded-full text-xs font-medium text-primary hover:bg-primary/10 transition-colors">
                            {{ $showAllMechanics ? __('games.action_show_less_mechanics') : __('games.action_show_more_mechanics') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Inline controls: player count, complexity, expansions toggle --}}
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                {{-- Player count slider --}}
                <div class="flex items-center gap-3 min-w-[200px]">
                    <span class="text-xs font-bold text-primary uppercase tracking-wide whitespace-nowrap">{{ __('games.field_player_count') }}</span>
                    <div class="flex-1 relative">
                        <div class="flex items-center gap-2">
                            <input type="range" min="1" max="10" step="1"
                                   value="{{ $min_players ?? 1 }}"
                                   wire:change="$set('min_players', $event.target.value == 1 ? null : $event.target.value)"
                                   aria-label="{{ __('common.field_minimum_players') }}"
                                   class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-primary
                                          [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-webkit-slider-thumb]:shadow-sm
                                          [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-primary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
                            <span class="text-xs text-on-surface-variant">–</span>
                            <input type="range" min="1" max="10" step="1"
                                   value="{{ $max_players ?? 10 }}"
                                   wire:change="$set('max_players', $event.target.value >= 10 ? null : $event.target.value)"
                                   aria-label="{{ __('common.field_maximum_players') }}"
                                   class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-primary
                                          [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-webkit-slider-thumb]:shadow-sm
                                          [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-primary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
                        </div>
                        <div class="flex justify-between mt-1">
                            <span class="text-[10px] text-on-surface-variant">{{ $min_players ?? '1' }}p</span>
                            <span class="text-[10px] text-on-surface-variant">{{ $max_players ?? '10+' }}p</span>
                        </div>
                    </div>
                </div>

                {{-- Complexity slider --}}
                <div class="flex items-center gap-3 min-w-[200px]">
                    <span class="text-xs font-bold text-primary uppercase tracking-wide whitespace-nowrap">{{ __('games.content_complexity') }}</span>
                    <div class="flex-1 relative">
                        <div class="flex items-center gap-2">
                            <input type="range" min="1" max="5" step="0.5"
                                   value="{{ $complexity_min ?? 1 }}"
                                   wire:change="$set('complexity_min', $event.target.value <= 1 ? null : $event.target.value)"
                                   aria-label="{{ __('games.field_minimum_complexity') }}"
                                   class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                                          [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-sm
                                          [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
                            <span class="text-xs text-on-surface-variant">–</span>
                            <input type="range" min="1" max="5" step="0.5"
                                   value="{{ $complexity_max ?? 5 }}"
                                   wire:change="$set('complexity_max', $event.target.value >= 5 ? null : $event.target.value)"
                                   aria-label="{{ __('games.field_maximum_complexity') }}"
                                   class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                                          [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-sm
                                          [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
                        </div>
                        <div class="flex justify-between mt-1">
                            <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_light') }}</span>
                            <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_heavy') }}</span>
                        </div>
                    </div>
                </div>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model.live="showExpansions" class="rounded border-outline text-primary focus:ring-primary/20" />
                    <span class="text-sm text-on-surface-variant">{{ __('games.action_include_expansions') }}</span>
                </label>

                @if($this->hasActiveFilters())
                    <button wire:click="clearFilters" class="text-xs text-primary hover:underline">
                        {{ __('common.action_clear_all') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Active Filters Bar --}}
        @if($this->hasActiveFilters())
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('games.heading_filters') }}:</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @foreach($category_ids as $catId)
                    @php($catName = $allCategories->firstWhere('id', $catId)?->translatedName())
                    @if($catName)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            {{ $catName }}
                        </span>
                    @endif
                @endforeach
                @foreach($mechanic_ids as $mechId)
                    @php($mechName = $allMechanics->firstWhere('id', $mechId)?->translatedName())
                    @if($mechName)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                            {{ $mechName }}
                        </span>
                    @endif
                @endforeach
                @if($min_players || $max_players)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                        {{ $min_players ?? '1' }}–{{ $max_players ?? '20' }} {{ __('common.content_players') }}
                    </span>
                @endif
                @if($complexity_min || $complexity_max)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        {{ $complexity_min ?? '1' }}–{{ $complexity_max ?? '5' }} {{ __('games.content_complexity') }}
                    </span>
                @endif
                @if($showExpansions)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ __('games.action_include_expansions') }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline ml-1">{{ __('common.action_clear_all') }}</button>
            </div>
        @endif

        {{-- Results count --}}
        <p class="text-sm text-on-surface-variant">
            {{ $systems->total() }} {{ $showExpansions ? __('games.content_showing_base_games_and_expansions') : __('games.content_showing_base_games') }}
        </p>

        {{-- ── Results Grid ────────────────────────────────────────────── --}}
        @if($systems->count())
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                @foreach($systems as $system)
                    <a href="{{ route('game-systems.show', $system->slug) }}"
                       wire:navigate
                       class="block bg-surface-container rounded-xl shadow-ambient hover:shadow-lg transition-all duration-200 overflow-hidden group">
                        {{-- Cover Image --}}
                        <div class="aspect-[4/3] bg-surface-container-high relative overflow-hidden">
                            @php($coverUrl = $system->getFirstMediaUrl('cover', 'thumb'))
                            @if($coverUrl)
                                <img src="{{ $coverUrl }}" alt="{{ $system->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                            @elseif($system->thumbnail_url)
                                <img src="{{ $system->thumbnail_url }}" alt="{{ $system->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                            @else
                                <div class="w-full h-full flex items-center justify-center bg-primary/5">
                                    <span class="material-symbols-outlined text-5xl text-primary/30" aria-hidden="true">casino</span>
                                </div>
                            @endif

                            {{-- Overlay badges --}}
                            <div class="absolute top-2 right-2 flex flex-col gap-1">
                                @if($system->active_sessions_count > 0)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-secondary-container text-on-secondary-container shadow-sm">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $system->active_sessions_count }}
                                    </span>
                                @endif
                                @if($system->expansion_count > 0)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-tertiary-container text-on-tertiary-container shadow-sm">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">extension</span>
                                        {{ $system->expansion_count }}
                                    </span>
                                @endif
                            </div>

                            {{-- BGG rank badge --}}
                            @if($system->bgg_rank)
                                <span class="absolute bottom-2 left-2 px-2 py-0.5 rounded-full text-xs font-bold bg-primary text-on-primary shadow-sm">
                                    #{{ number_format($system->bgg_rank) }}
                                </span>
                            @endif
                        </div>

                        {{-- Card Body --}}
                        <div class="p-3 space-y-1.5">
                            <h3 class="font-heading font-semibold text-sm text-on-surface leading-tight line-clamp-2 group-hover:text-primary transition-colors">
                                {{ $system->name }}
                            </h3>

                            {{-- Metadata row --}}
                            <div class="flex items-center gap-3 text-xs text-on-surface-variant">
                                @if($system->min_players || $system->max_players)
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }}
                                    </span>
                                @endif
                                @if($system->average_play_time)
                                    <span class="flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                                        {{ $system->average_play_time }}{{ __('games.content_min') }}
                                    </span>
                                @endif
                                @if($system->bgg_average_rating)
                                    <span class="flex items-center gap-0.5" title="{{ __('games.content_bgg_rating') }}">
                                        <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">star</span>
                                        {{ number_format($system->bgg_average_rating, 1) }}
                                    </span>
                                @endif
                            </div>

                            {{-- Complexity bar --}}
                            @if($system->bgg_average_weight && $system->bgg_average_weight > 0)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 bg-surface-container-highest rounded-full overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r from-green-400 via-amber-400 to-red-400" style="width: {{ min(100, ($system->bgg_average_weight / 5) * 100) }}%"></div>
                                    </div>
                                    <span class="text-[10px] text-on-surface-variant w-6 text-right">{{ number_format($system->bgg_average_weight, 1) }}</span>
                                </div>
                            @endif

                            {{-- Category chips --}}
                            @if($system->categories->count())
                                <div class="flex flex-wrap gap-1">
                                    @foreach($system->categories->take(2) as $cat)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-primary/5 text-primary">{{ $cat->translatedName() }}</span>
                                    @endforeach
                                    @if($system->categories->count() > 2)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-surface-container text-on-surface-variant">+{{ $system->categories->count() - 2 }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $systems->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface-container rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">casino</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('games.content_no_game_systems_match_filters') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($this->hasActiveFilters())
                        <button wire:click="clearFilters" class="text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
                    @else
                        {{ __('games.content_game_systems_will_appear_here_once_they_are_added') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
