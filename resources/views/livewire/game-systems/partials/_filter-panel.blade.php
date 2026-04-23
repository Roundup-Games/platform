{{-- Shared filter panel contents — included in desktop sidebar and mobile sheet --}}

{{-- ── Categories ────────────────────────────────────────────────── --}}
<div>
    <h3 class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('games.heading_categories') }}</h3>
    <div class="flex flex-wrap gap-1.5 max-h-48 overflow-y-auto">
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

{{-- ── Play Styles (TTRPG mode only) ────────────────────────────── --}}
@if($type === 'ttrpg' && !empty($playStyleGroups))
    @foreach($playStyleGroups as $groupKey => $group)
        <div>
            <h3 class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ $group['label'] }}</h3>
            <div class="flex flex-wrap gap-1.5">
                @foreach($group['options'] as $styleValue => $styleLabel)
                    @php($styleIcon = $group['icons'][$styleValue] ?? '')
                    @php($styleDesc = $group['descriptions'][$styleValue] ?? '')
                    <button wire:click="togglePlayStyle('{{ $styleValue }}')"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors duration-150 {{ in_array($styleValue, $play_styles) ? 'bg-tertiary-container text-on-tertiary-container shadow-sm ring-1 ring-tertiary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-tertiary-container/50 hover:text-on-tertiary-container' }}"
                            title="{{ $styleDesc }}">
                        @if($styleIcon)
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $styleIcon }}</span>
                        @endif
                        {{ $styleLabel }}
                    </button>
                @endforeach
            </div>
        </div>
    @endforeach
@endif

{{-- ── Mechanics (non-TTRPG or all mode) ─────────────────────────── --}}
@if($type !== 'ttrpg')
<div>
    <h3 class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('games.heading_mechanics') }}</h3>
    <div class="flex flex-wrap gap-1.5 max-h-48 overflow-y-auto">
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
@endif

{{-- ── Player Count ──────────────────────────────────────────────── --}}
<div>
    <h3 class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('games.field_player_count') }}</h3>
    <div class="flex items-center gap-2">
        <div class="flex-1">
            <label for="min-players" class="sr-only">{{ __('common.field_minimum_players') }}</label>
            <input id="min-players"
                   type="number"
                   min="1" max="20" step="1"
                   placeholder="1"
                   aria-label="{{ __('common.field_minimum_players') }}"
                   wire:model.live.debounce.300ms="min_players"
                   class="w-full px-2.5 py-1.5 bg-surface-container-high border border-outline-variant/20 rounded-lg text-xs text-on-surface text-center placeholder:text-on-surface-variant/50 focus:border-primary/30 focus:ring-1 focus:ring-primary/20" />
        </div>
        <span class="text-xs text-on-surface-variant">–</span>
        <div class="flex-1">
            <label for="max-players" class="sr-only">{{ __('common.field_maximum_players') }}</label>
            <input id="max-players"
                   type="number"
                   min="1" max="20" step="1"
                   placeholder="20"
                   aria-label="{{ __('common.field_maximum_players') }}"
                   wire:model.live.debounce.300ms="max_players"
                   class="w-full px-2.5 py-1.5 bg-surface-container-high border border-outline-variant/20 rounded-lg text-xs text-on-surface text-center placeholder:text-on-surface-variant/50 focus:border-primary/30 focus:ring-1 focus:ring-primary/20" />
        </div>
    </div>
</div>

{{-- ── Complexity ────────────────────────────────────────────────── --}}
<div>
    <h3 class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('games.content_complexity') }}</h3>
    <div class="space-y-2">
        <div class="flex items-center gap-2">
            <div class="flex-1 relative">
                <input type="range" min="1" max="5" step="0.5"
                       value="{{ $complexity_min ?? 1 }}"
                       wire:change="$set('complexity_min', $event.target.value <= 1 ? null : $event.target.value)"
                       aria-label="{{ __('games.field_minimum_complexity') }}"
                       class="w-full h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                              [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-sm
                              [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
            </div>
            <span class="text-xs text-on-surface-variant w-6 text-right">{{ $complexity_min ?? '1' }}</span>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex-1 relative">
                <input type="range" min="1" max="5" step="0.5"
                       value="{{ $complexity_max ?? 5 }}"
                       wire:change="$set('complexity_max', $event.target.value >= 5 ? null : $event.target.value)"
                       aria-label="{{ __('games.field_maximum_complexity') }}"
                       class="w-full h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                              [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-sm
                              [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-sm" />
            </div>
            <span class="text-xs text-on-surface-variant w-6 text-right">{{ $complexity_max ?? '5' }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_light') }}</span>
            <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_heavy') }}</span>
        </div>
    </div>
</div>

{{-- ── Expansions Toggle ─────────────────────────────────────────── --}}
<div>
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" wire:model.live="showExpansions" class="rounded border-outline text-primary focus:ring-primary/20" />
        <span class="text-sm text-on-surface-variant">{{ __('games.action_include_expansions') }}</span>
    </label>
</div>

{{-- ── Clear All (inside panel) ──────────────────────────────────── --}}
@if($this->hasActiveFilters())
    <button wire:click="clearFilters" class="w-full py-2 text-xs font-medium text-primary hover:bg-primary/5 rounded-lg transition-colors border border-primary/20">
        {{ __('common.action_clear_all') }}
    </button>
@endif
