@props([
    'preferenceType' => 'favorite',
])

<div
    x-data="{ activeIndex: -1 }"
    @click.away="$wire.closeDropdown()"
    @keydown.escape.window="$wire.showExpansionPicker = false; $wire.selectedBaseId = null"
    class="relative"
>
    {{-- Search Input --}}
    <div class="relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
        <input
            type="text"
            id="preference-picker-{{ $preferenceType }}"
            class="w-full pl-10 pr-10 rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
            placeholder="{{ $preferenceType === 'favorite' ? __('Search game systems to add as favorites...') : __('Search game systems to avoid...') }}"
            autocomplete="off"
            wire:model.live.debounce.300ms="search"
            wire:focus="setOpen"
            aria-haspopup="listbox"
            wire:aria-expanded="isOpen"
            aria-autocomplete="list"
            role="combobox"
        />

        @if(strlen($search) > 0)
            <button
                type="button"
                wire:click="$set('search', '')"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface transition-colors"
                aria-label="{{ __('Clear search') }}"
            >
                <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
            </button>
        @endif
    </div>

    {{-- Conflict Warning --}}
    @if($conflictMessage)
        <div class="mt-2 flex items-start gap-2 px-3 py-2 bg-tertiary/10 border border-tertiary/20 rounded-lg">
            <span class="material-symbols-outlined text-tertiary text-lg flex-shrink-0 mt-0.5" aria-hidden="true">warning</span>
            <p class="text-sm text-tertiary">{{ $conflictMessage }}</p>
        </div>
    @endif

    {{-- Dropdown: Search Results --}}
    @if($isOpen && strlen($search) >= 2 && !$showExpansionPicker)
        @php($results = $this->searchResults)

        <div
            class="absolute z-50 mt-1 w-full bg-surface-container-low rounded-lg shadow-lg border border-outline/20 max-h-80 overflow-y-auto"
            role="listbox"
            aria-label="{{ __('Game systems') }}"
        >
            @if($results->isEmpty())
                <div class="px-4 py-3 text-sm text-on-surface-variant text-center">
                    {{ __('No game systems found.') }}
                </div>
            @else
                @foreach($results as $index => $system)
                    @php($alreadySelected = in_array($system->id, $selectedIds))
                    <button
                        type="button"
                        wire:click="pickFromSearch({{ $system->id }})"
                        @mouseenter="activeIndex = {{ $index }}"
                        :class="activeIndex === {{ $index }} ? 'bg-surface-container-high' : ''"
                        class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-surface-container-high transition-colors focus:outline-none focus:bg-surface-container-high @if($alreadySelected) opacity-50 @endif"
                        role="option"
                        :aria-selected="activeIndex === {{ $index }}"
                        @if($alreadySelected) disabled @endif
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
                                @if($alreadySelected)
                                    <span class="text-xs text-on-surface-variant ml-1">({{ __('already selected') }})</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium">
                                    {{ __('Base Game') }}
                                </span>
                                @if($system->expansions_count > 0)
                                    <span class="text-xs text-on-surface-variant">
                                        {{ $system->expansions_count }} {{ __('expansions') }}
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

                        @if($system->expansions_count > 0)
                            <span class="material-symbols-outlined text-on-surface-variant text-lg flex-shrink-0" aria-hidden="true">chevron_right</span>
                        @endif
                    </button>
                @endforeach
            @endif
        </div>
    @endif

    {{-- Expansion Sub-Picker --}}
    @if($showExpansionPicker)
        @php($expansionOptions = $this->expansionOptions)

        <div class="mt-2 bg-surface-container rounded-lg border border-outline/20 overflow-hidden">
            <div class="px-4 py-2.5 bg-surface-container-high border-b border-outline/10 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-secondary text-lg" aria-hidden="true">extension</span>
                    <span class="text-sm font-medium text-on-surface">
                        {{ __('Choose base game or expansion') }}
                    </span>
                </div>
                <button
                    type="button"
                    wire:click="cancelExpansionPicker"
                    class="text-on-surface-variant hover:text-on-surface transition-colors"
                    aria-label="{{ __('Cancel') }}"
                >
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
                </button>
            </div>

            <div class="max-h-60 overflow-y-auto divide-y divide-outline/5">
                @foreach($expansionOptions as $option)
                    @php($alreadySelected = in_array($option->id, $selectedIds))
                    <button
                        type="button"
                        wire:click="pickExpansion({{ $option->id }})"
                        @class([
                            'w-full text-left px-4 py-3 flex items-center gap-3 transition-colors',
                            'hover:bg-surface-container-high focus:outline-none focus:bg-surface-container-high',
                            'opacity-50' => $alreadySelected,
                        ])
                        @if($alreadySelected) disabled @endif
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
                                @if($alreadySelected)
                                    <span class="text-xs text-on-surface-variant ml-1">({{ __('already selected') }})</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                @if($option->is_base)
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary font-medium">
                                        {{ __('Base Game') }}
                                    </span>
                                    <span class="text-xs text-on-surface-variant">
                                        {{ $preferenceType === 'favorite' ? __('implies all expansions') : __('blocks all expansions') }}
                                    </span>
                                @else
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">
                                        {{ __('Expansion') }}
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
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Selected Items: Chip List --}}
    @php($systems = $this->selectedSystems)

    @if($systems->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($systems as $system)
                <div class="flex items-center gap-2 px-3 py-1.5 bg-surface-container rounded-full border border-outline/10 group">
                    @if($system->thumbnail_url)
                        <img src="{{ $system->thumbnail_url }}" alt="" class="w-5 h-5 rounded object-cover flex-shrink-0" aria-hidden="true">
                    @else
                        <span class="material-symbols-outlined text-on-surface-variant text-sm flex-shrink-0" aria-hidden="true">casino</span>
                    @endif

                    <span class="text-sm text-on-surface max-w-[200px] truncate">
                        {{ $system->name }}
                    </span>

                    @if($system->base_game_id)
                        {{-- Expansion chip: show which base it belongs to --}}
                        @if($system->baseGame)
                            <span class="text-xs text-on-surface-variant">
                                ({{ $system->baseGame?->name }})
                            </span>
                        @endif
                        <span class="text-xs px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">
                            {{ __('Expansion') }}
                        </span>
                    @else
                        {{-- Base game chip --}}
                        @if($preferenceType === 'favorite' && $system->expansions_count > 0)
                            <span class="text-xs text-on-surface-variant">
                                +{{ $system->expansions_count }} {{ __('implied') }}
                            </span>
                        @endif
                        @if($preferenceType === 'avoid' && $system->expansions_count > 0)
                            <span class="text-xs text-on-surface-variant">
                                +{{ $system->expansions_count }} {{ __('blocked') }}
                            </span>
                        @endif
                    @endif

                    <button
                        type="button"
                        wire:click="remove({{ $system->id }})"
                        class="text-on-surface-variant hover:text-error transition-colors ml-0.5"
                        aria-label="{{ __('Remove :name', ['name' => $system->name]) }}"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">delete</span>
                    </button>
                </div>
            @endforeach
        </div>
    @else
        <p class="mt-2 text-sm text-on-surface-variant italic">
            @if($preferenceType === 'favorite')
                {{ __('No favorite game systems selected yet.') }}
            @else
                {{ __('No avoided game systems selected yet.') }}
            @endif
        </p>
    @endif
</div>
