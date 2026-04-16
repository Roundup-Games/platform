<div>
    <x-hero title="{{ __('Games') }}" :subtitle="__('Find tabletop sessions, one-shots, and campaigns to join.')" />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        {{-- Search & Primary Filters --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="{{ __('Search games') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search games by name or description...') }}"
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
            <select wire:model.live="game_system_id" aria-label="{{ __('Filter by game system') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Systems') }}</option>
                @foreach($gameSystems as $system)
                    <option value="{{ $system->id }}">{{ $system->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="date" aria-label="{{ __('Filter by date') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('Any Date') }}</option>
                <option value="upcoming">{{ __('Upcoming') }}</option>
                <option value="this_week">{{ __('This Week') }}</option>
                <option value="this_month">{{ __('This Month') }}</option>
            </select>
            <select wire:model.live="price" aria-label="{{ __('Filter by price') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('Any Price') }}</option>
                <option value="free">{{ __('Free') }}</option>
                <option value="paid">{{ __('Paid') }}</option>
            </select>
        </div>

        {{-- Secondary Filters --}}
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="experience_level" aria-label="{{ __('Filter by experience level') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Levels') }}</option>
                @foreach($experienceLevels as $level)
                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="language" aria-label="{{ __('Filter by language') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Languages') }}</option>
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
            <span class="text-sm text-on-surface-variant">{{ __('Complexity:') }}</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_min" placeholder="{{ __('Min') }}" aria-label="{{ __('Minimum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            <span class="text-on-surface-variant">–</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_max" placeholder="{{ __('Max') }}" aria-label="{{ __('Maximum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
        </div>

        {{-- Active Filters --}}
        @if($search || $game_system_id || $experience_level || !empty($vibe_flags) || $language || $date || $price || $complexity_min || $complexity_max)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('Filters:') }}</span>
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
                @if($date)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ __(ucfirst(str_replace('_', ' ', $date))) }}
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
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('Clear all') }}</button>
            </div>
        @endif

        {{-- Games Grid --}}
        @if($games->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($games as $game)
                    <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                        <div class="h-1.5 bg-outline-variant/30"></div>

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors line-clamp-1">
                                    {{ $game->name }}
                                </h3>
                                @if($game->price > 0)
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                        {{ format_currency($game->price, false) }}
                                    </span>
                                @else
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        {{ __('Free') }}
                                    </span>
                                @endif
                            </div>

                            {{-- Game System + Visibility badges --}}
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                @if($game->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                        {{ $game->gameSystem->name }}
                                    </span>
                                @endif
                                @if($game->visibility === 'protected')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">lock</span>
                                        {{ __('Members Only') }}
                                    </span>
                                @endif
                                @if($game->experience_level)
                                    @php($levelEnum = App\Enums\ExperienceLevel::tryFrom($game->experience_level))
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                        {{ $levelEnum?->label() ?? $game->experience_level }}
                                    </span>
                                @endif
                            </div>

                            {{-- Campaign link --}}
                            @if($game->campaign)
                                <a href="{{ route('campaigns.detail', $game->campaign->id) }}" wire:navigate
                                   onclick="event.stopPropagation()"
                                   class="mt-1.5 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container hover:text-primary transition-colors">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">campaign</span>
                                    {{ $game->campaign->name }}
                                </a>
                            @endif

                            {{-- Date & Duration --}}
                            <p class="text-sm text-on-surface-variant flex items-center gap-1">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">calendar_today</span>
                                {{ format_date($game->date_time, 'datetime') }}
                            </p>
                            @if($game->expected_duration)
                                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                                    {{ $game->expected_duration }}h
                                </p>
                            @endif

                            {{-- Language --}}
                            @php($langEnum = App\Enums\ContentLanguage::tryFrom($game->language))
                            @if($langEnum)
                                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">translate</span>
                                    {{ $langEnum->label() }}
                                </p>
                            @endif

                            {{-- Description --}}
                            @if($game->description)
                                <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($game->description, 120) }}</p>
                            @endif

                            {{-- Vibe flags --}}
                            @if($game->vibe_flags && count($game->vibe_flags))
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach(array_slice($game->vibe_flags, 0, 4) as $flag)
                                        @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                                        @if($flagEnum)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary/10 text-primary">
                                                {{ $flagEnum->label() }}
                                            </span>
                                        @endif
                                    @endforeach
                                    @if(count($game->vibe_flags) > 4)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-container text-on-surface-variant">
                                            +{{ count($game->vibe_flags) - 4 }}
                                        </span>
                                    @endif
                                </div>
                            @endif

                            {{-- Player count / Participants --}}
                            <div class="mt-3 flex items-center gap-3 text-xs text-on-surface-variant">
                                @if($game->min_players || $game->max_players)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                                        @if($game->min_players && $game->max_players)
                                            {{ $game->min_players }}–{{ $game->max_players }}
                                        @elseif($game->min_players)
                                            {{ $game->min_players }}+
                                        @else
                                            ≤{{ $game->max_players }}
                                        @endif
                                    </span>
                                @endif
                                @if(isset($game->participants_count))
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                        {{ $game->participants_count }} {{ __('joined') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $games->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">sports_esports</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('No games found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($search || $game_system_id || $experience_level || !empty($vibe_flags) || $language || $date || $price || $complexity_min || $complexity_max)
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __('Check back soon for upcoming games!') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>
