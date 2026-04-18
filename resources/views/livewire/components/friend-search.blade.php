<div
    x-data="{ activeIndex: -1 }"
    @click.away="$wire.closeDropdown()"
    @keydown.escape.window="$wire.closeDropdown()"
    class="space-y-2"
>
    {{-- Selected Friends Chips --}}
    @php($selectedFriends = $this->selectedFriends)
    @if($selectedFriends->isNotEmpty())
        <div class="flex flex-wrap gap-2">
            @foreach($selectedFriends as $friend)
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-primary/10 text-primary text-sm"
                >
                    @if($friend->avatar_url)
                        <img src="{{ $friend->avatar_url }}" alt="" class="w-5 h-5 rounded-full object-cover" aria-hidden="true">
                    @else
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                    @endif
                    <span class="font-medium">{{ $friend->name }}</span>
                    <button
                        type="button"
                        wire:click="removeFriend({{ $friend->id }})"
                        class="text-primary/60 hover:text-primary transition-colors"
                        aria-label="Remove {{ $friend->name }}"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                    </button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- Search Input --}}
    <div class="relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
        <input
            type="text"
            id="friend-search-input"
            class="w-full pl-10 pr-10 rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
            placeholder="{{ __('people.action_search_friends') }}"
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
                aria-label="{{ __('common.action_clear_selection') }}"
            >
                <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
            </button>
        @endif
    </div>

    {{-- Dropdown: Search Results --}}
    @if($isOpen && strlen($search) >= 2)
        @php($results = $this->searchResults)

        <div
            class="absolute z-50 mt-1 w-full bg-surface-container-low rounded-lg shadow-lg border border-outline/20 max-h-80 overflow-y-auto"
            role="listbox"
            aria-label="{{ __('people.content_friends') }}"
        >
            @if($results->isEmpty())
                <div class="px-4 py-3 text-sm text-on-surface-variant text-center">
                    {{ __('people.content_no_friends_found') }}
                </div>
            @else
                @foreach($results as $index => $friend)
                    <button
                        type="button"
                        wire:click="selectFriend({{ $friend->id }})"
                        @mouseenter="activeIndex = {{ $index }}"
                        :class="activeIndex === {{ $index }} ? 'bg-surface-container-high' : ''"
                        class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-surface-container-high transition-colors focus:outline-none focus:bg-surface-container-high"
                        role="option"
                        :aria-selected="activeIndex === {{ $index }}"
                    >
                        @if($friend->avatar_url)
                            <img src="{{ $friend->avatar_url }}" alt="" class="w-10 h-10 rounded-full object-cover flex-shrink-0" aria-hidden="true">
                        @else
                            <div class="w-10 h-10 rounded-full bg-surface-container flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">person</span>
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-on-surface truncate">
                                {{ $friend->name }}
                            </div>
                            <div class="text-xs text-on-surface-variant truncate">
                                {{ $friend->email }}
                            </div>
                        </div>

                        @if(in_array($friend->id, $selectedIds))
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">check_circle</span>
                        @endif
                    </button>
                @endforeach
            @endif
        </div>
    @endif
</div>
