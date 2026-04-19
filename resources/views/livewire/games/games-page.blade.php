<div class="py-8">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 space-y-10">

        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.content_games') }}</h1>
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="rounded-lg bg-secondary-container text-on-secondary-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-lg bg-error-container text-on-error-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                {{ session('error') }}
            </div>
        @endif

        {{-- My Games Section --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-semibold text-on-surface">{{ __('games.heading_my_games') }}</h2>
                <a href="{{ route('games.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-secondary text-on-secondary text-sm font-medium hover:bg-secondary/90 transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('games.action_create_game') }}
                </a>
            </div>

            @if($ownedGames->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">sports_esports</span>
                    <p class="text-on-surface-variant text-sm">{{ __('games.content_no_owned_games') }}</p>
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="mt-3 inline-flex items-center gap-1 text-sm text-secondary hover:text-secondary/80 transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('games.action_create_game') }}
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($ownedGames as $game)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            {{-- Game Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <h3 class="text-base font-medium text-on-surface truncate">
                                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="hover:text-secondary transition-colors">
                                            {{ $game->name }}
                                        </a>
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $game->status === 'scheduled' ? 'bg-primary-container text-on-primary-container' : ($game->status === 'completed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                        {{ __('games.status_' . $game->status) }}
                                    </span>
                                    @if($game->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            {{ $game->gameSystem->name }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                    @if($game->date_time)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                            {{ format_date($game->date_time, 'datetime') }}
                                        </span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $game->participants->count() }}/{{ $game->max_players ?? '∞' }}
                                    </span>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 shrink-0">
                                @if($game->status === 'scheduled')
                                    <button wire:click="cancelGame('{{ $game->id }}')"
                                            wire:confirm="{{ __('games.confirm_cancel_game') }}"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-error hover:bg-error/10 transition-colors">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">cancel</span>
                                        {{ __('games.action_cancel_game') }}
                                    </button>
                                    <button wire:click="completeGame('{{ $game->id }}')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-secondary hover:bg-secondary/10 transition-colors">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                                        {{ __('games.action_complete_game') }}
                                    </button>
                                @endif
                                <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    {{ __('games.action_view_game') }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Games I'm In Section --}}
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('games.heading_games_im_in') }}</h2>

            @if($participatingGames->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">group</span>
                    <p class="text-on-surface-variant text-sm">{{ __('games.content_no_games_joined') }}</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($participatingGames as $game)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            {{-- Game Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <h3 class="text-base font-medium text-on-surface truncate">
                                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="hover:text-secondary transition-colors">
                                            {{ $game->name }}
                                        </a>
                                    </h3>
                                    @if($game->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            {{ $game->gameSystem->name }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                    @if($game->date_time)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                            {{ format_date($game->date_time, 'datetime') }}
                                        </span>
                                    @endif
                                    @if($game->owner)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                            {{ $game->owner->name }}
                                        </span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $game->participants->count() }}/{{ $game->max_players ?? '∞' }}
                                    </span>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    {{ __('games.action_view_game') }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Open Invitations Section --}}
        @if($pendingInvitations->isNotEmpty())
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('games.heading_open_invitations') }}</h2>

            <div class="space-y-3">
                @foreach($pendingInvitations as $invitation)
                    @php $game = $invitation->game; @endphp
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4 border-l-4 border-primary">
                        {{-- Game Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <h3 class="text-base font-medium text-on-surface truncate">
                                    {{ $game->name }}
                                </h3>
                                @if($game->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        {{ $game->gameSystem->name }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                @if($game->date_time)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                        {{ format_date($game->date_time, 'datetime') }}
                                    </span>
                                @endif
                                @if($game->owner)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                        {{ $game->owner->name }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="acceptInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-secondary hover:bg-secondary/10 transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                {{ __('games.action_accept_invitation') }}
                            </button>
                            <button wire:click="declineInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-error hover:bg-error/10 transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                {{ __('games.action_decline_invitation') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Community Section --}}
        <section>
            <div class="flex items-center gap-3 mb-4">
                <div class="flex-1 h-px bg-outline-variant/30"></div>
                <h2 class="text-xl font-heading font-semibold text-on-surface">{{ __('games.heading_community') }}</h2>
                <div class="flex-1 h-px bg-outline-variant/30"></div>
            </div>

            {{-- Search & Primary Filters --}}
            <div class="flex flex-col sm:flex-row gap-3 mb-4">
                <div class="flex-1 relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                    <input type="text" aria-label="{{ __('games.action_search_games') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('games.action_search_games_by_name_or_description') }}"
                           class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                </div>
                <select wire:model.live="game_system_id" aria-label="{{ __('games.action_filter_by_game_system') }}"
                        class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                    <option value="">{{ __('discovery.content_all_systems') }}</option>
                    @foreach($gameSystems as $system)
                        <option value="{{ $system->id }}">{{ $system->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="date" aria-label="{{ __('discovery.field_filter_by_date') }}"
                        class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                    <option value="">{{ __('discovery.field_any_date') }}</option>
                    <option value="upcoming">{{ __('common.field_upcoming') }}</option>
                    <option value="this_week">{{ __('common.content_this_week') }}</option>
                    <option value="this_month">{{ __('common.content_this_month') }}</option>
                </select>
                <select wire:model.live="price" aria-label="{{ __('discovery.field_filter_by_price') }}"
                        class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                    <option value="">{{ __('discovery.field_any_price') }}</option>
                    <option value="free">{{ __('billing.content_free') }}</option>
                    <option value="paid">{{ __('billing.content_paid') }}</option>
                </select>
            </div>

            {{-- Secondary Filters --}}
            <div class="flex flex-wrap gap-3 mb-4">
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
                <div class="space-y-3 mb-4">
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
            <div class="flex items-center gap-3 mb-4">
                <span class="text-sm text-on-surface-variant">{{ __('games.content_complexity') }}</span>
                <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_min" placeholder="{{ __('common.field_min') }}" aria-label="{{ __('games.field_minimum_complexity') }}"
                       class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
                <span class="text-on-surface-variant">–</span>
                <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_max" placeholder="{{ __('common.field_max') }}" aria-label="{{ __('games.field_maximum_complexity') }}"
                       class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            </div>

            {{-- Active Filters --}}
            @if($this->hasActiveFilters())
                <div class="flex items-center gap-2 flex-wrap mb-4">
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
                    <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('common.action_clear_all') }}</button>
                </div>
            @endif

            {{-- Community Games Grid --}}
            @if($communityGames->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($communityGames as $game)
                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="block bg-surface-container-low rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                            <div class="h-1.5 bg-outline-variant/30"></div>

                            <div class="p-5">
                                <div class="flex items-start justify-between mb-2">
                                    <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-secondary transition-colors line-clamp-1">
                                        {{ $game->name }}
                                    </h3>
                                    @if($game->price > 0)
                                        <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                            {{ format_currency($game->price, false) }}
                                        </span>
                                    @else
                                        <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                            {{ __('billing.content_free') }}
                                        </span>
                                    @endif
                                </div>

                                {{-- Game System + Visibility badges --}}
                                <div class="flex items-center gap-2 mb-3 flex-wrap">
                                    @if($game->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                            {{ $game->gameSystem?->name }}
                                        </span>
                                    @endif
                                    @if($game->visibility === 'protected')
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">lock</span>
                                            {{ __('common.content_members_only') }}
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
                                       class="mt-1.5 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container hover:text-secondary transition-colors">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">campaign</span>
                                        {{ $game->campaign?->name }}
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
                                            {{ trans_choice('common.content_joined', $game->participants_count) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-6">
                    {{ $communityGames->links() }}
                </div>
            @else
                <div class="text-center py-16 bg-surface-container-low rounded-xl shadow-ambient">
                    <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">sports_esports</span>
                    <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('games.content_no_community_games') }}</h3>
                    <p class="mt-1 text-sm text-on-surface-variant">
                        @if($this->hasActiveFilters())
                            {{ __('common.action_try_adjusting_your_filters') }}
                        @else
                            {{ __('games.content_check_back_soon_for_upcoming_games') }}
                        @endif
                    </p>
                </div>
            @endif
        </section>

    </div>
</div>
