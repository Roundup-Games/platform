<div>
    {{-- ── Compact Header ────────────────────────────────────────── --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('games.action_discover_games_campaigns') }}</h1>
            <p class="mt-1 text-sm text-on-primary/80">{{ __('discovery.action_find_games_and_campaigns_that_match_your_vibe') }}</p>

            {{-- ── Search ─────────────────────────────────────── --}}
            <div class="mt-4 relative max-w-xl">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-primary/60 text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="{{ __('discovery.action_search') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('games.action_search_games_and_campaigns') }}"
                       class="w-full pl-10 pr-4 py-2.5 bg-on-primary/10 border border-on-primary/20 rounded-full text-on-primary placeholder:text-on-primary/50 focus:bg-on-primary/20 focus:border-on-primary/40 focus:ring-2 focus:ring-on-primary/20" />
            </div>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-5">

        {{-- ── Recommended for You (logged-in users only) ────────── --}}
        @auth
            @if($recommendations)
                <section class="space-y-3">
                    <h2 class="text-lg font-heading font-semibold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">auto_awesome</span>
                        {{ __('discovery.field_recommended_for_you') }}
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($recommendations as $item)
                            @if($item->discoverable_type === 'game')
                                @include('livewire.discovery.partials.game-card', ['game' => $item])
                            @else
                                @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                            @endif
                        @endforeach
                    </div>
                    <hr class="border-outline-variant/30 mt-4" />
                </section>
            @endif
        @endauth

        {{-- ── Top Band: Type + Time + Location ──────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">

            {{-- Type pills (replaces mode tabs) --}}
            <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1" role="radiogroup" aria-label="{{ __('campaigns.content_session_type') }}">
                <button wire:click="setMode('all')"
                        role="radio" aria-checked="{{ $mode === 'all' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'all' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_either') }}
                </button>
                <button wire:click="setMode('games')"
                        role="radio" aria-checked="{{ $mode === 'games' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'games' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_one_shot') }}
                </button>
                <button wire:click="setMode('campaigns')"
                        role="radio" aria-checked="{{ $mode === 'campaigns' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'campaigns' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('campaigns.content_campaign') }}
                </button>
            </div>

            {{-- Time pills (contextual to type) --}}
            @if($mode === 'all' || $mode === 'games')
                <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1 overflow-x-auto" role="radiogroup" aria-label="{{ __('common.field_time_frame') }}">
                    <button wire:click="setDate('')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ !$date ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('discovery.field_any_date') }}
                    </button>
                    <button wire:click="setDate('upcoming')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'upcoming' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.field_upcoming') }}
                    </button>
                    <button wire:click="setDate('this_week')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'this_week' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.content_this_week') }}
                    </button>
                    <button wire:click="setDate('this_month')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $date === 'this_month' ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('common.content_this_month') }}
                    </button>
                </div>
            @endif

            @if($mode === 'campaigns')
                <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1 overflow-x-auto" role="radiogroup" aria-label="{{ __('campaigns.content_schedule') }}">
                    <button wire:click="setRecurrence('')"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ !$recurrence ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                        {{ __('discovery.content_any_schedule') }}
                    </button>
                    @foreach($recurrenceOptions as $option)
                        <button wire:click="setRecurrence('{{ $option }}')"
                                class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $recurrence === $option ? 'bg-secondary-container text-on-secondary-container shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                            {{ __(ucfirst(str_replace('-', ' ', $option))) }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Location line --}}
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant sm:ml-auto">
                <span class="material-symbols-outlined text-base" aria-hidden="true">location_on</span>
                @if($guestLat && $guestLng)
                    <span>{{ round($guestLat, 1) }}°, {{ round($guestLng, 1) }}°</span>
                @else
                    <span>{{ __('location.action_set_your_location') }}</span>
                @endif
                <button wire:click="requestGuestLocation" class="text-primary hover:underline text-xs">{{ __('common.action_change') }}</button>
            </div>
        </div>

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

                {{-- Game System select --}}
                <div>
                    <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('games.content_game_system') }}</p>
                    <select wire:model.live="game_system_id" aria-label="{{ __('games.action_filter_by_game_system') }}"
                            class="w-full sm:w-auto min-w-[200px] bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">{{ __('discovery.content_all_systems') }}</option>
                        @foreach($gameSystems as $system)
                            <option value="{{ $system->id }}">{{ $system->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Category pills (from curated list) --}}
                @if($curatedCategories->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('common.content_categories') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($curatedCategories as $category)
                                <button
                                    wire:click="toggleCategory({{ $category->id }})"
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                        {{ in_array($category->id, $category_ids) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                >
                                    {{ $category->name }}
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
                                    wire:click="toggleMechanic({{ $mechanic->id }})"
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                        {{ in_array($mechanic->id, $mechanic_ids) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                >
                                    {{ $mechanic->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Vibe flag groups --}}
                @if($vibeFlagGroups)
                    <div class="space-y-3">
                        @foreach($vibeFlagGroups as $groupKey => $group)
                            <div>
                                <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ $group['label'] }}</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($group['options'] as $flagValue => $flagLabel)
                                        <button
                                            wire:click="toggleVibeFlag('{{ $flagValue }}')"
                                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                                {{ in_array($flagValue, $vibe_flags) ? 'bg-tertiary/15 text-on-tertiary-container ring-1 ring-tertiary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                        >
                                            {{ $flagLabel }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

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
                        @endforeach>
                    </div>
                @endif

                {{-- Selects row: Experience Level / Language / Price / Complexity --}}
                <div class="flex flex-wrap gap-3">
                    <select wire:model.live="experience_level" aria-label="{{ __('discovery.action_filter_by_experience_level') }}"
                            class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">{{ __('discovery.content_all_levels') }}</option>
                        @foreach($experienceLevels as $level)
                            <option value="{{ $level->value }}">{{ $level->label() }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="language" aria-label="{{ __('discovery.action_filter_by_language') }}"
                            class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">{{ __('discovery.content_all_languages') }}</option>
                        @foreach($languages as $lang)
                            <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="price" aria-label="{{ __('discovery.field_filter_by_price') }}"
                            class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">{{ __('discovery.field_any_price') }}</option>
                        <option value="free">{{ __('billing.content_free') }}</option>
                        <option value="paid">{{ __('billing.content_paid') }}</option>
                    </select>
                </div>

                {{-- Complexity Range --}}
                <div class="flex items-center gap-3">
                    <span class="text-sm text-on-surface-variant">{{ __('games.content_complexity') }}</span>
                    <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_min" placeholder="{{ __('common.field_min') }}" aria-label="{{ __('games.field_minimum_complexity') }}"
                           class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
                    <span class="text-on-surface-variant">–</span>
                    <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_max" placeholder="{{ __('common.field_max') }}" aria-label="{{ __('games.field_maximum_complexity') }}"
                           class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
                </div>
            </div>
        </div>

        {{-- ── Active Filter Chips ──────────────────────────────────── --}}
        @php($activeFilters = $this->hasActiveFilters())
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
                        {{ __(ucfirst(str_replace('_', ' ', $date))) }}
                    </span>
                @endif
                @if($recurrence)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ __(ucfirst(str_replace('-', ' ', $recurrence))) }}
                    </span>
                @endif
                @if($game_system_id)
                    @php($systemName = $gameSystems->firstWhere('id', $game_system_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $systemName }}
                    </span>
                @endif
                @foreach($category_ids as $catId)
                    @php($cat = $curatedCategories->firstWhere('id', $catId))
                    @if($cat)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            {{ $cat->name }}
                        </span>
                    @endif
                @endforeach
                @foreach($mechanic_ids as $mechId)
                    @php($mech = $curatedMechanics->firstWhere('id', $mechId))
                    @if($mech)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            {{ $mech->name }}
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
                        {{ __(ucfirst($price)) }}
                    </span>
                @endif
                @if($complexity_min || $complexity_max)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ $complexity_min ?? '1' }}–{{ $complexity_max ?? '5' }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
            </div>
        @endif

        {{-- ── Results Grid ────────────────────────────────────────── --}}
        @if($results->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($results as $item)
                    @if($item->discoverable_type === 'game')
                        @include('livewire.discovery.partials.game-card', ['game' => $item])
                    @else
                        @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                    @endif
                @endforeach
            </div>

            <div class="mt-6">
                {{ $results->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">explore</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('common.content_no_results_found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($activeFilters)
                        {{ __('common.action_try_adjusting_your_filters') }}
                    @else
                        {{ __('games.content_check_back_soon_for_new_games_and_campaigns') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
