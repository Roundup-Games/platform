<div>
    <x-hero title="{{ __('Explore Games') }}" :subtitle="__('Discover new game systems, browse ratings, and find your next favorite game.')" />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">

        {{-- ── Search & Primary Filters ──────────────────────────── --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text"
                       aria-label="{{ __('Search game systems') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Search by name...') }}"
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>

            <select wire:model.live="category_id"
                    aria-label="{{ __('Filter by category') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Categories') }}</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="mechanic_id"
                    aria-label="{{ __('Filter by mechanic') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Mechanics') }}</option>
                @foreach($mechanics as $mechanic)
                    <option value="{{ $mechanic->id }}">{{ $mechanic->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── Player Count & Complexity Range ────────────────────── --}}
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm text-on-surface-variant">{{ __('Players:') }}</span>
            <input type="number" min="1" max="20"
                   wire:model.live="min_players"
                   placeholder="{{ __('Min') }}"
                   aria-label="{{ __('Minimum players') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            <span class="text-on-surface-variant">–</span>
            <input type="number" min="1" max="20"
                   wire:model.live="max_players"
                   placeholder="{{ __('Max') }}"
                   aria-label="{{ __('Maximum players') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />

            <span class="text-sm text-on-surface-variant ml-4">{{ __('Complexity:') }}</span>
            <input type="number" min="1" max="5" step="0.5"
                   wire:model.live="complexity_min"
                   placeholder="{{ __('Min') }}"
                   aria-label="{{ __('Minimum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            <span class="text-on-surface-variant">–</span>
            <input type="number" min="1" max="5" step="0.5"
                   wire:model.live="complexity_max"
                   placeholder="{{ __('Max') }}"
                   aria-label="{{ __('Maximum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
        </div>

        {{-- ── Active Filters ──────────────────────────────────────── --}}
        @if($this->hasActiveFilters())
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('Filters:') }}</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @if($min_players || $max_players)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $min_players ?? '1' }}–{{ $max_players ?? '20' }} {{ __('players') }}
                    </span>
                @endif
                @if($complexity_min || $complexity_max)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        {{ $complexity_min ?? '1' }}–{{ $complexity_max ?? '5' }} {{ __('weight') }}
                    </span>
                @endif
                @if($category_id)
                    @php($catName = $categories->firstWhere('id', $category_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                        {{ $catName }}
                    </span>
                @endif
                @if($mechanic_id)
                    @php($mechName = $mechanics->firstWhere('id', $mechanic_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $mechName }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('Clear all') }}</button>
            </div>
        @endif

        {{-- ── Results Grid ────────────────────────────────────────── --}}
        @if($systems->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($systems as $system)
                    <a href="{{ route('discover', ['game_system_id' => $system->id]) }}"
                       wire:navigate
                       class="block bg-surface-container rounded-xl shadow-ambient hover:shadow-md transition-shadow duration-200 overflow-hidden group">
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

                            {{-- Active sessions badge --}}
                            @if($system->active_sessions_count > 0)
                                <span class="absolute top-2 right-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-secondary-container text-on-secondary-container shadow-sm">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                    {{ $system->active_sessions_count }}
                                </span>
                            @endif
                        </div>

                        {{-- Card Body --}}
                        <div class="p-3 space-y-1.5">
                            <h3 class="font-heading font-semibold text-sm text-on-surface leading-tight line-clamp-2 group-hover:text-primary transition-colors">
                                {{ $system->name }}
                            </h3>

                            {{-- Player count --}}
                            @if($system->min_players || $system->max_players)
                                <p class="text-xs text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                    {{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }}
                                </p>
                            @endif

                            {{-- BGG Rating & Weight --}}
                            <div class="flex items-center gap-3 text-xs text-on-surface-variant">
                                @if($system->bgg_average_rating)
                                    <span class="flex items-center gap-0.5" title="{{ __('BGG Rating') }}">
                                        <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">star</span>
                                        {{ number_format($system->bgg_average_rating, 1) }}
                                    </span>
                                @endif
                                @if($system->bgg_average_weight)
                                    <span class="flex items-center gap-0.5" title="{{ __('Complexity') }}">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">fitness_center</span>
                                        {{ number_format($system->bgg_average_weight, 1) }}
                                    </span>
                                @endif
                            </div>

                            {{-- BGG Rank --}}
                            @if($system->bgg_rank)
                                <p class="text-xs text-primary font-medium">
                                    #{{ number_format($system->bgg_rank) }}
                                </p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $systems->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">casino</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('No game systems found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($this->hasActiveFilters())
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __('Game systems will appear here once they are added.') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
