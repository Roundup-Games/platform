@props(['fieldId' => 'game-system', 'label' => __('games.content_game_system'), 'error' => ''])

<div
    x-data="{ activeIndex: -1 }"
    @click.away="$wire.closeDropdown()"
    @keydown.escape.window="$wire.closeDropdown()"
    class="relative"
>
    <label for="{{ $fieldId }}-search" class="block text-sm font-medium text-on-surface mb-1">
        {{ $label }}
    </label>

    {{-- Search Input --}}
    <div class="relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
        <input
            type="text"
            id="{{ $fieldId }}-search"
            {{ $attributes->merge(['class' => 'w-full pl-10 pr-10 rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors']) }}
            placeholder="{{ __('games.placeholder_search_game_systems_picker') }}"
            autocomplete="off"
            wire:model.live.debounce.300ms="search"
            wire:focus="setOpen"
            aria-haspopup="listbox"
            wire:aria-expanded="isOpen"
            aria-autocomplete="list"
            role="combobox"
        />

        {{-- Clear button --}}
        @if($value)
            <button
                type="button"
                wire:click="clearSelection"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface transition-colors"
                aria-label="{{ __('common.action_clear_selection') }}"
            >
                <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
            </button>
        @elseif(strlen($search) > 0)
            <button
                type="button"
                wire:click="clearSelection"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface transition-colors"
                aria-label="{{ __('discovery.action_clear_search') }}"
            >
                <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
            </button>
        @endif
    </div>

    {{-- Validation Error --}}
    @if($error)
        <p class="mt-1 text-sm text-error">{{ $error }}</p>
    @endif

    {{-- Selected System Indicator (when not in expansion picker mode) --}}
    @if($value && !$showExpansionPicker)
        @php($selectedSystem = \App\Models\GameSystem::find($value))
        @if($selectedSystem)
            <div class="mt-2 flex items-center gap-2 px-3 py-2 bg-surface-container rounded-lg">
                @if($selectedSystem->thumbnail_url)
                    <img src="{{ $selectedSystem->thumbnail_url }}" alt="" class="w-8 h-8 rounded object-cover" aria-hidden="true">
                @else
                    <span class="material-symbols-outlined text-on-surface-variant text-lg" aria-hidden="true">casino</span>
                @endif
                <span class="text-sm text-on-surface flex-1">{{ $selectedSystem->name }}</span>
                @if($selectedSystem->base_game_id)
                    <span class="text-xs px-2 py-0.5 rounded-full bg-secondary/10 text-secondary">{{ __('games.content_expansion') }}</span>
                @endif
            </div>
        @endif
    @endif

    {{-- Dropdown: Search Results --}}
    @if($isOpen && strlen($search) >= 2)
        @php($results = $this->searchResults)

        <div
            class="absolute z-50 mt-1 w-full bg-surface-container-low rounded-lg shadow-lg border border-outline/20 max-h-80 overflow-y-auto"
            role="listbox"
            aria-label="{{ __('games.label_game_systems') }}"
        >
            @if($results->isEmpty())
                <div class="px-4 py-3 text-sm text-on-surface-variant text-center">
                    {{ __('games.content_no_game_systems_found') }}
                    @auth
                        <a href="{{ route('game-systems.request', ['locale' => app()->getLocale(), 'name' => $search, 'type' => $gameType]) }}" wire:navigate class="inline-flex items-center gap-1 mt-1 text-secondary hover:text-secondary/80 transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">add_circle</span>
                            {{ __('games.request_cta_link') }}
                        </a>
                    @endauth
                </div>
            @else
                @foreach($results as $index => $system)
                    <button
                        type="button"
                        wire:click="pickFromSearch('{{ $system->id }}')"
                        @mouseenter="activeIndex = {{ $index }}"
                        :class="activeIndex === {{ $index }} ? 'bg-surface-container-high' : ''"
                        class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-surface-container-high transition-colors focus:outline-none focus:bg-surface-container-high"
                        role="option"
                        :aria-selected="activeIndex === {{ $index }}"
                    >
                        @if($system->thumbnail_url)
                            <img src="{{ $system->thumbnail_url }}" alt="" class="w-10 h-10 rounded object-cover flex-shrink-0" aria-hidden="true">
                        @else
                            <div class="w-10 h-10 rounded bg-surface-container flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">casino</span>
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-on-surface truncate">
                                {{ $system->name }}
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium">
                                    {{ __('games.content_base_game') }}
                                </span>
                                @if($system->expansions_count > 0)
                                    <span class="text-xs text-on-surface-variant">
                                        + {{ trans_choice('games.content_count_expansions', $system->expansions_count) }}
                                    </span>
                                @endif
                                @if($system->bgg_rank)
                                    <span class="text-xs text-on-surface-variant">
                                        <span class="material-symbols-outlined text-xs align-middle" aria-hidden="true">trending_up</span>
                                        #{{ $system->bgg_rank }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </button>
                @endforeach
            @endif
        </div>

    {{-- Dropdown: Favorites (when search is empty or minimal) --}}
    @elseif($isOpen && strlen($search) < 2)
        @php($favorites = $this->favoriteSystems)

        @if($favorites->isNotEmpty())
            <div
                class="absolute z-50 mt-1 w-full bg-surface-container-low rounded-lg shadow-lg border border-outline/20 max-h-80 overflow-y-auto"
                role="listbox"
                aria-label="{{ __('games.content_your_favorite_game_systems') }}"
            >
                <div class="px-4 py-2 text-xs font-medium text-on-surface-variant uppercase tracking-wider flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">favorite</span>
                    {{ __('common.content_your_favorites') }}
                </div>
                @foreach($favorites as $system)
                    <button
                        type="button"
                        wire:click="pickFavorite('{{ $system->id }}')"
                        class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-surface-container-high transition-colors focus:outline-none focus:bg-surface-container-high"
                        role="option"
                    >
                        @if($system->thumbnail_url)
                            <img src="{{ $system->thumbnail_url }}" alt="" class="w-10 h-10 rounded object-cover flex-shrink-0" aria-hidden="true">
                        @else
                            <div class="w-10 h-10 rounded bg-surface-container flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">casino</span>
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-on-surface truncate">
                                {{ $system->name }}
                            </div>
                            @php($expCount = $system->expansions()->count())
                            @if($expCount > 0)
                                <span class="text-xs text-on-surface-variant">
                                    + {{ trans_choice('games.content_count_expansions', $expCount) }}
                                </span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Expansion Sub-Picker --}}
    @if($showExpansionPicker)
        @php($expansionOptions = $this->expansionOptions)

        <div class="mt-3 bg-surface-container rounded-lg border border-outline/20 overflow-hidden">
            <div class="px-4 py-2.5 bg-surface-container-high border-b border-outline/10 flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-lg" aria-hidden="true">extension</span>
                <span class="text-sm font-medium text-on-surface">
                    {{ __('games.action_choose_specific_game_or_expansion') }}
                </span>
            </div>

            <div class="max-h-60 overflow-y-auto divide-y divide-outline/5">
                @foreach($expansionOptions as $option)
                    <button
                        type="button"
                        wire:click="pickExpansion('{{ $option->id }}')"
                        @class([
                            'w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-surface-container-high transition-colors',
                            'bg-surface-container-high' => $value === $option->id,
                        ])
                    >
                        @if($option->thumbnail_url)
                            <img src="{{ $option->thumbnail_url }}" alt="" class="w-8 h-8 rounded object-cover flex-shrink-0" aria-hidden="true">
                        @else
                            <div class="w-8 h-8 rounded bg-surface-container flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant text-sm" aria-hidden="true">
                                    {{ $option->is_base ? 'casino' : 'extension' }}
                                </span>
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-on-surface truncate {{ $option->is_base ? 'font-medium' : '' }}">
                                {{ $option->name }}
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                @if($option->is_base)
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium">
                                        {{ __('games.content_base_game') }}
                                    </span>
                                @else
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">
                                        {{ __('games.content_expansion') }}
                                    </span>
                                @endif
                                @if($option->bgg_average_rating > 0)
                                    <span class="text-xs text-on-surface-variant">
                                        {{ number_format((float) $option->bgg_average_rating, 1) }} ★
                                    </span>
                                @endif
                                @if($option->bgg_rank)
                                    <span class="text-xs text-on-surface-variant">
                                        #{{ $option->bgg_rank }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        @if($value === $option->id)
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">check_circle</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="px-4 py-2 bg-surface-container-high border-t border-outline/10">
                <button
                    type="button"
                    wire:click="showExpansionPicker = false"
                    class="text-xs text-on-surface-variant hover:text-on-surface transition-colors"
                >
                    {{ __('common.action_cancel') }}
                </button>
            </div>
        </div>
    @endif
</div>
