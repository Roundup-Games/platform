{{-- ── Expandable "Narrow it down" Section ───────────────── --}}
<div x-data="{ expanded: false }">
    <button @click="expanded = !expanded"
            class="flex items-center gap-2 text-sm font-medium text-primary hover:text-primary/80 transition-colors"
            :aria-expanded="expanded">
        <span class="material-symbols-outlined text-base transition-transform" :class="{ 'rotate-180': expanded }" aria-hidden="true">expand_more</span>
        {{ __('common.content_narrow_it_down') }}
    </button>

    <div x-show="expanded"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="mt-4 space-y-5"
         x-cloak>

        {{-- Game System picker (search-based with expansion support) --}}
        <div>
            <livewire:components.game-system-picker
                :fieldId="'discovery-game-system'"
                :label="__('games.content_game_system')"
                :value="$game_system_id"
            />
        </div>

        {{-- Category pills (from curated list) --}}
        @if($curatedCategories->isNotEmpty())
            <div>
                <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('common.content_categories') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($curatedCategories as $category)
                        <button
                            wire:click="toggleCategory('{{ $category->id }}')"
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                {{ in_array($category->id, $category_ids) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                        >
                            {{ $category->translatedName() }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Mechanic pills (from curated list) --}}
        @if($curatedMechanics->isNotEmpty())
            <div>
                <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('games.content_mechanics') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($curatedMechanics as $mechanic)
                        <button
                            wire:click="toggleMechanic('{{ $mechanic->id }}')"
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                {{ in_array($mechanic->id, $mechanic_ids) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                        >
                            {{ $mechanic->translatedName() }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Vibe preference picker (paired segmented + tri-state chips) --}}
        <div>
            <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('common.content_vibes') }}</p>
            <livewire:components.vibe-preference-picker
                :preferences="$vibePreferences"
                mode="selection"
            />
        </div>

        {{-- Safety tool groups --}}
        @if($safetyToolGroups)
            <div class="space-y-3">
                @foreach($safetyToolGroups as $groupKey => $group)
                    <div>
                        <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ $group['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($group['options'] as $toolValue => $toolLabel)
                                <button
                                    wire:click="toggleSafetyTool('{{ $toolValue }}')"
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                        {{ in_array($toolValue, $safety_tools) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                >
                                    {{ $toolLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Selects row: Experience Level / Language / Price / Complexity --}}
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="experience_level" aria-label="{{ __('discovery.action_filter_by_experience_level') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_all_levels') }}</option>
                @foreach($experienceLevels as $level)
                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                @endforeach
            </select>

            <select wire:model.live="language" aria-label="{{ __('discovery.action_filter_by_language') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_all_languages') }}</option>
                @foreach($languages as $lang)
                    <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                @endforeach
            </select>

            <select wire:model.live="price" aria-label="{{ __('discovery.field_filter_by_price') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.field_any_price') }}</option>
                <option value="free">{{ __('billing.content_free') }}</option>
                <option value="paid">{{ __('billing.content_paid') }}</option>
            </select>
        </div>

        {{-- Complexity Range --}}
        <div class="flex items-center gap-3">
            <span class="text-sm text-on-surface-variant">{{ __('games.content_complexity') }}</span>
            <div class="flex items-center gap-2 flex-1 max-w-xs">
                <input type="range" min="1" max="5" step="0.5"
                       value="{{ $complexity_min ?? 1 }}"
                       wire:change="$set('complexity_min', $event.target.value <= 1 ? null : $event.target.value)"
                       aria-label="{{ __('games.field_minimum_complexity') }}"
                       class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                              [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-xs
                              [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-xs" />
                <span class="text-on-surface-variant text-sm">–</span>
                <input type="range" min="1" max="5" step="0.5"
                       value="{{ $complexity_max ?? 5 }}"
                       wire:change="$set('complexity_max', $event.target.value >= 5 ? null : $event.target.value)"
                       aria-label="{{ __('games.field_maximum_complexity') }}"
                       class="flex-1 h-1.5 bg-surface-container-highest rounded-full appearance-none cursor-pointer accent-secondary
                              [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-secondary [&::-webkit-slider-thumb]:shadow-xs
                              [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-secondary [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:shadow-xs" />
            </div>
            <div class="flex justify-between w-full max-w-xs mt-0.5">
                <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_light') }}</span>
                <span class="text-[10px] text-on-surface-variant">{{ __('games.content_weight_heavy') }}</span>
            </div>
        </div>
    </div>
</div>

{{-- ── Active Filter Chips ──────────────────────────────────── --}}
@if($activeFilters)
    <div class="flex items-center gap-2 flex-wrap">
        <span class="text-sm text-on-surface-variant">{{ __('common.content_filters') }}</span>
        @if($search)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                "{{ $search }}"
            </span>
        @endif
        @if($mode !== 'all')
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                {{ $mode === 'games' ? __('common.content_one_shot') : __('campaigns.content_campaign') }}
            </span>
        @endif
        @if($date)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                {{ match($date) {
                    'upcoming' => __('common.field_upcoming'),
                    'this_week' => __('common.content_this_week'),
                    'this_month' => __('common.content_this_month'),
                    default => __(ucfirst(str_replace('_', ' ', $date))),
                } }}
            </span>
        @endif
        @if($recurrence)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                {{ match($recurrence) {
                    'weekly' => __('campaigns.content_weekly'),
                    'bi-weekly' => __('campaigns.content_bi-weekly'),
                    'monthly' => __('campaigns.content_monthly'),
                    default => __(ucfirst(str_replace('-', ' ', $recurrence))),
                } }}
            </span>
        @endif
        @if($game_system_id)
            @php($systemName = \App\Models\GameSystem::find($game_system_id)?->name)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                {{ $systemName }}
            </span>
        @endif
        @foreach($category_ids as $catId)
            @php($cat = $curatedCategories->firstWhere('id', $catId))
            @if($cat)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ $cat->translatedName() }}
                </span>
            @endif
        @endforeach
        @foreach($mechanic_ids as $mechId)
            @php($mech = $curatedMechanics->firstWhere('id', $mechId))
            @if($mech)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ $mech->translatedName() }}
                </span>
            @endif
        @endforeach
        @if($experience_level)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                {{ App\Enums\ExperienceLevel::tryFrom($experience_level)?->label() ?? $experience_level }}
            </span>
        @endif
        @foreach($vibe_flags as $flag)
            @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
            @if($flagEnum)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                    {{ $flagEnum->label() }}
                </span>
            @endif
        @endforeach
        @foreach($safety_tools as $tool)
            @php($toolEnum = App\Enums\SafetyTool::tryFrom($tool))
            @if($toolEnum)
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ $toolEnum->label() }}
                </span>
            @endif
        @endforeach
        @if($language)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                {{ App\Enums\ContentLanguage::tryFrom($language)?->label() ?? $language }}
            </span>
        @endif
        @if($price)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                {{ $price === 'free' ? __('billing.content_free') : __('billing.content_paid') }}
            </span>
        @endif
        @if($complexity_min || $complexity_max)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                {{ $complexity_min ?? '1' }}–{{ $complexity_max ?? '5' }}
            </span>
        @endif
        @if($radius > 0)
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                {{ $radius }} km
            </span>
        @endif
        <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
    </div>
@endif
