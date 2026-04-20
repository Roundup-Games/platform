<div x-data="{ filterPanelOpen: false }">
    <x-hero :title="__('games.action_explore_game_systems')" :subtitle="__('games.content_game_systems_knowledge_base_subtitle')" />

    {{-- ── Mobile filter backdrop ─────────────────────────────────────── --}}
    <div x-show="filterPanelOpen"
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="filterPanelOpen = false"
         class="fixed inset-0 bg-black/40 z-40 lg:hidden"
         aria-hidden="true"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        {{-- ── Search Bar ──────────────────────────────────────────────────── --}}
        <div class="max-w-2xl mx-auto mb-6">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant text-xl" aria-hidden="true">search</span>
                <input type="text"
                       aria-label="{{ __('games.action_search_game_systems') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('games.action_search_game_systems') }}"
                       class="w-full pl-12 pr-4 py-3 bg-surface-container-high border border-transparent rounded-full text-on-surface text-sm placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
        </div>

        {{-- ── Top toolbar: mobile filter toggle + results count ───────────── --}}
        <div class="flex items-center justify-between gap-4 mb-4">
            <p class="text-sm text-on-surface-variant">
                {{ $systems->total() }} {{ $showExpansions ? __('games.content_showing_base_games_and_expansions') : __('games.content_showing_base_games') }}
            </p>
            <button @click="filterPanelOpen = !filterPanelOpen"
                    class="lg:hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-primary/10 hover:text-primary transition-colors"
                    aria-label="{{ __('games.heading_filters') }}">
                <span class="material-symbols-outlined text-base" aria-hidden="true">tune</span>
                {{ __('games.heading_filters') }}
                @if($this->hasActiveFilters())
                    <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
                @endif
            </button>
        </div>

        {{-- ── Active Filter Chips Bar ─────────────────────────────────────── }}
        @if($this->hasActiveFilters())
            <div class="flex items-center gap-2 flex-wrap mb-4">
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

        {{-- ── Main layout: sidebar + grid ────────────────────────────────── --}}
        <div class="flex gap-6">

            {{-- ── Filter Sidebar (desktop: always visible; mobile: slide-over sheet) --}}
            <aside class="hidden lg:block w-64 flex-shrink-0">
                <div class="sticky top-24 space-y-5 max-h-[calc(100vh-8rem)] overflow-y-auto pr-1">
                    @include('livewire.game-systems.partials._filter-panel')
                </div>
            </aside>

            {{-- ── Mobile Sheet ──────────────────────────────────────────────── --}}
            <div x-show="filterPanelOpen"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-8"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-8"
                 @click.stop
                 class="fixed inset-x-0 bottom-0 z-50 lg:hidden bg-surface rounded-t-2xl shadow-2xl max-h-[85vh] overflow-y-auto"
                 role="dialog"
                 aria-label="{{ __('games.heading_filters') }}">
                <div class="sticky top-0 bg-surface z-10 px-4 pt-3 pb-2 border-b border-outline-variant/10 flex items-center justify-between">
                    <h2 class="text-sm font-bold text-on-surface uppercase tracking-wide">{{ __('games.heading_filters') }}</h2>
                    <button @click="filterPanelOpen = false" class="p-1 text-on-surface-variant hover:text-primary transition-colors" aria-label="{{ __('common.action_close') }}">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">close</span>
                    </button>
                </div>
                <div class="p-4 space-y-5">
                    @include('livewire.game-systems.partials._filter-panel')
                </div>
            </div>

            {{-- ── Results Area ──────────────────────────────────────────────── --}}
            <div class="flex-1 min-w-0">
                @if($systems->count())
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4">
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

                                    {{-- Expansion indicator badge --}}
                                    @if($system->base_game_id)
                                        <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-xs font-semibold bg-primary-container text-on-primary-container shadow-sm">
                                            <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">extension</span>
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
                    {{-- ── Empty State ──────────────────────────────────────────────────── --}}
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
    </div>
</div>
