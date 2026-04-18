<div>
    <x-hero title="{{ __('campaigns.content_campaigns') }}" :subtitle="__('campaigns.content_find_recurring_campaigns_to_join')" />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        {{-- Search & Primary Filters --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="{{ __('campaigns.action_search_campaigns') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('campaigns.action_search_campaigns_by_name_or_description') }}"
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
            <select wire:model.live="game_system_id" aria-label="{{ __('games.action_filter_by_game_system') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_all_systems') }}</option>
                @foreach($gameSystems as $system)
                    <option value="{{ $system->id }}">{{ $system->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="recurrence" aria-label="{{ __('campaigns.action_filter_by_recurrence') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.content_any_schedule') }}</option>
                @foreach($recurrenceOptions as $option)
                    <option value="{{ $option }}">{{ __(ucfirst(str_replace('-', ' ', $option))) }}</option>
                @endforeach
            </select>
            <select wire:model.live="price" aria-label="{{ __('discovery.field_filter_by_price') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('discovery.field_any_price') }}</option>
                <option value="free">{{ __('billing.content_free') }}</option>
                <option value="paid">{{ __('billing.content_paid') }}</option>
            </select>
        </div>

        {{-- Secondary Filters --}}
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
        </div>

        {{-- Vibe Flag Filters (grouped) --}}
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
                                        {{ in_array($flagValue, $vibe_flags) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                >
                                    {{ $flagLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Complexity Range --}}
        <div class="flex items-center gap-3">
            <span class="text-sm text-on-surface-variant">{{ __('games.content_complexity_2') }}</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_min" placeholder="{{ __('common.field_min') }}" aria-label="{{ __('games.field_minimum_complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            <span class="text-on-surface-variant">–</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_max" placeholder="{{ __('common.field_max') }}" aria-label="{{ __('games.field_maximum_complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
        </div>

        {{-- Active Filters --}}
        @if($this->hasActiveFilters())
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('common.content_filters') }}</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @if($game_system_id)
                    @php($systemName = $gameSystems->firstWhere('id', $game_system_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $systemName }}
                    </span>
                @endif
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
                @if($recurrence)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ __(ucfirst(str_replace('-', ' ', $recurrence))) }}
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

        {{-- Campaigns Grid --}}
        @if($campaigns->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($campaigns as $campaign)
                    <a href="{{ route('campaigns.detail', $campaign->id) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                        <div class="h-1.5 bg-outline-variant/30"></div>

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors line-clamp-1">
                                    {{ $campaign->name }}
                                </h3>
                                @if($campaign->price_per_session > 0)
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                        {{ format_currency($campaign->price_per_session, false) }}
                                    </span>
                                @else
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        {{ __('billing.content_free') }}
                                    </span>
                                @endif
                            </div>

                            {{-- Game System + Visibility badges --}}
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                @if($campaign->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                        {{ $campaign->gameSystem?->name }}
                                    </span>
                                @endif
                                @if($campaign->visibility === 'protected')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">lock</span>
                                        {{ __('common.content_members_only') }}
                                    </span>
                                @endif
                                @if($campaign->experience_level)
                                    @php($levelEnum = App\Enums\ExperienceLevel::tryFrom($campaign->experience_level))
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                        {{ $levelEnum?->label() ?? $campaign->experience_level }}
                                    </span>
                                @endif
                            </div>

                            {{-- Recurrence & Schedule --}}
                            <p class="text-sm text-on-surface-variant flex items-center gap-1">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">repeat</span>
                                {{ __(ucfirst(str_replace('-', ' ', $campaign->recurrence ?? ''))) }}
                            </p>
                            @if($campaign->time_of_day)
                                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                                    {{ $campaign->time_of_day }}
                                    @if($campaign->session_duration)
                                        ({{ $campaign->session_duration }}h)
                                    @endif
                                </p>
                            @endif

                            {{-- Language --}}
                            @php($langEnum = App\Enums\ContentLanguage::tryFrom($campaign->language))
                            @if($langEnum)
                                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">translate</span>
                                    {{ $langEnum->label() }}
                                </p>
                            @endif

                            {{-- Description --}}
                            @if($campaign->description)
                                <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($campaign->description, 120) }}</p>
                            @endif

                            {{-- Vibe flags --}}
                            @if($campaign->vibe_flags && count($campaign->vibe_flags))
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach(array_slice($campaign->vibe_flags, 0, 4) as $flag)
                                        @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                                        @if($flagEnum)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary/10 text-primary">
                                                {{ $flagEnum->label() }}
                                            </span>
                                        @endif
                                    @endforeach
                                    @if(count($campaign->vibe_flags) > 4)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-container text-on-surface-variant">
                                            +{{ count($campaign->vibe_flags) - 4 }}
                                        </span>
                                    @endif
                                </div>
                            @endif

                            {{-- Player count / Sessions --}}
                            <div class="mt-3 flex items-center gap-3 text-xs text-on-surface-variant">
                                @if($campaign->min_players || $campaign->max_players)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                                        @if($campaign->min_players && $campaign->max_players)
                                            {{ $campaign->min_players }}–{{ $campaign->max_players }}
                                        @elseif($campaign->min_players)
                                            {{ $campaign->min_players }}+
                                        @else
                                            ≤{{ $campaign->max_players }}
                                        @endif
                                    </span>
                                @endif
                                @if(isset($campaign->sessions_count))
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">event_note</span>
                                        {{ $campaign->sessions_count }} {{ __('campaigns.content_sessions_2') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $campaigns->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">campaign</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('campaigns.content_no_campaigns_found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($this->hasActiveFilters())
                        {{ __('common.action_try_adjusting_your_filters') }}
                    @else
                        {{ __('campaigns.content_check_back_soon_for_new_campaigns') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
