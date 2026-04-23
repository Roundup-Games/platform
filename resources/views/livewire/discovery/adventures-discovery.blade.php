<div>
    {{-- ── Compact Header ────────────────────────────────────────── --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('games.action_discover_adventures') }}</h1>
            <p class="mt-1 text-sm text-on-primary/80">{{ __('discovery.action_find_your_next_adventure') }}</p>

            {{-- ── Search ─────────────────────────────────────── --}}
            <div class="mt-4 relative max-w-xl">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-primary/60 text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="{{ __('discovery.action_search') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('games.action_search_adventures') }}"
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
                            @if($item->discoverable_type === 'campaign')
                                @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                            @else
                                @include('livewire.discovery.partials.game-card', ['game' => $item])
                            @endif
                        @endforeach
                    </div>
                    <hr class="border-outline-variant/30 mt-4" />
                </section>
            @endif
        @endauth

        {{-- ── Top Band: Session Type + Session Zero + Location ──── --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">

            {{-- Session type pills: All / Campaigns / One-shots --}}
            <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1" role="radiogroup" aria-label="{{ __('campaigns.content_session_type') }}">
                <button wire:click="setSessionType('')"
                        role="radio" aria-checked="{{ !$session_type ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ !$session_type ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_all') }}
                </button>
                <button wire:click="setSessionType('campaign')"
                        role="radio" aria-checked="{{ $session_type === 'campaign' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $session_type === 'campaign' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    <span class="material-symbols-outlined text-xs mr-0.5 align-middle" aria-hidden="true">campaign</span>
                    {{ __('campaigns.content_campaigns') }}
                </button>
                <button wire:click="setSessionType('oneshot')"
                        role="radio" aria-checked="{{ $session_type === 'oneshot' ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $session_type === 'oneshot' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                    {{ __('common.content_one_shots') }}
                </button>
            </div>

            {{-- Session Zero toggle (prominent badge) --}}
            <button wire:click="toggleSessionZero"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium transition-colors whitespace-nowrap {{ $session_zero ? 'bg-tertiary-container text-on-tertiary-container shadow-sm ring-1 ring-tertiary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                    :aria-pressed="{{ $session_zero ? 'true' : 'false' }}">
                <span class="material-symbols-outlined text-base" aria-hidden="true">shield</span>
                {{ __('safety.content_session_zero_support') }}
            </button>

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

        {{-- ── Radius toggle (when guest has location) ──────────── --}}
        @if($hasLocation)
            <div class="flex flex-wrap items-center gap-2"
                 role="radiogroup"
                 aria-label="{{ __('discovery.action_search_radius') }}">
                <span class="text-sm font-medium text-on-surface-variant mr-1">
                    <span class="material-symbols-outlined text-sm align-middle mr-0.5" aria-hidden="true">straighten</span>
                    {{ __('common.content_radius') }}:
                </span>
                <button wire:click="setRadius(0)"
                        class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $radius == 0 ? 'bg-tertiary-container text-on-tertiary-container shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                        role="radio"
                        aria-checked="{{ $radius == 0 ? 'true' : 'false' }}">
                    {{ __('discovery.field_any_distance') }}
                </button>
                @foreach($radiusOptions as $option)
                    <button wire:click="setRadius({{ $option }})"
                            class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $radius == $option ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                            role="radio"
                            aria-checked="{{ $radius == $option ? 'true' : 'false' }}">
                        {{ $option }} km
                    </button>
                @endforeach
            </div>
        @endif

        {{-- ── Fallback radius notice ──────────────────────────────── --}}
        @if($usingFallbackRadius)
            <div class="p-3 bg-primary-container/30 rounded-xl border border-primary/20">
                <p class="text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">info</span>
                    {{ __('campaigns.content_no_sessions_found_within_radius', [
                        'radius' => (int) $radius,
                        'fallback' => 100,
                    ]) }}
                </p>
            </div>
        @endif

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
                        :fieldId="'adventures-discovery-game-system'"
                        :label="__('games.content_game_system')"
                        :value="$game_system_id"
                    />
                </div>

                {{-- Safety tool groups (PROMOTED to top for TTRPG emphasis) --}}
                @if($safetyToolGroups)
                    <div class="space-y-3">
                        <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wide flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">shield</span>
                            {{ __('safety.content_safety_tools') }}
                        </p>
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

                {{-- Genre category chips (from TTRPG-scoped curated list) --}}
                @if($curatedCategories->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('discovery.content_genre_categories') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($curatedCategories as $category)
                                <button
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors bg-surface-container-high text-on-surface-variant hover:bg-surface-container"
                                >
                                    {{ $category->translatedName() }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Play style chips from PlayStyle enum with icons and hover descriptions --}}
                @if(!empty($playStyleGroups))
                    @foreach($playStyleGroups as $groupKey => $group)
                        <div class="space-y-2">
                            <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ $group['label'] }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($group['options'] as $styleValue => $styleLabel)
                                    @php($styleIcon = $group['icons'][$styleValue] ?? '')
                                    @php($styleDesc = $group['descriptions'][$styleValue] ?? '')
                                    <button
                                        wire:click="togglePlayStyle('{{ $styleValue }}')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                            {{ in_array($styleValue, $play_styles) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                        title="{{ $styleDesc }}"
                                    >
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

                {{-- Vibe preference picker (paired segmented + tri-state chips) --}}
                <div>
                    <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ __('common.content_vibes') }}</p>
                    <livewire:components.vibe-preference-picker
                        :preferences="$vibePreferences"
                        mode="selection"
                    />
                </div>

                {{-- Selects row: Experience Level / Language / Price --}}
                {{-- NO mechanics filter, NO complexity filter for TTRPGs --}}
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
                @if($session_type === 'campaign')
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">campaign</span>
                        {{ __('campaigns.content_campaigns') }}
                    </span>
                @elseif($session_type === 'oneshot')
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ __('common.content_one_shots') }}
                    </span>
                @endif
                @if($session_zero)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">shield</span>
                        {{ __('safety.content_session_zero_support') }}
                    </span>
                @endif
                @if($game_system_id)
                    @php($systemName = \App\Models\GameSystem::find($game_system_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $systemName }}
                    </span>
                @endif
                @foreach($play_styles as $style)
                    @php($playStyleEnum = App\Enums\PlayStyle::tryFrom($style))
                    @if($playStyleEnum)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">{{ $playStyleEnum->icon() }}</span>
                            {{ $playStyleEnum->label() }}
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
                @if($radius > 0)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                        {{ $radius }} km
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
            </div>
        @endif

        {{-- ── Results Grid ────────────────────────────────────────── --}}
        @if($results->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($results as $item)
                    @if($item->discoverable_type === 'campaign')
                        @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                    @else
                        @include('livewire.discovery.partials.game-card', ['game' => $item])
                    @endif
                @endforeach>
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
                        {{ __('games.content_check_back_soon_for_new_adventures') }}
                    @endif
                </p>
            </div>
        @endif

        {{-- ── Cross-track hint: board games ──────────────────────── --}}
        @if($boardGameSessionCount > 0)
            <div class="mt-8 p-4 bg-primary/5 rounded-xl text-center">
                <span class="material-symbols-outlined text-primary text-sm" aria-hidden="true">casino</span>
                {{ trans_choice('discovery.content_also_into_board_games', $boardGameSessionCount, ['count' => $boardGameSessionCount]) }}
                <a href="{{ route('discover.board-games') }}" wire:navigate class="text-primary font-medium hover:underline">
                    {{ __('discovery.action_browse_board_games') }}
                </a>
            </div>
        @endif
    </div>
</div>
