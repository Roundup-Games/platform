<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">

        {{-- Profile Header --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-start gap-6">
                {{-- Avatar --}}
                <div class="shrink-0">
                    @php
                        $avatarMedia = $profileUser->getFirstMedia('avatar');
                    @endphp

                    @if($avatarMedia)
                        <img src="{{ $avatarMedia->getUrl() }}"
                             alt="{{ $profileUser->name }}"
                             class="w-20 h-20 rounded-full object-cover ring-2 ring-outline-variant/30" />
                    @else
                        <div class="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center text-primary text-2xl font-bold font-heading">
                            {{ strtoupper(\Illuminate\Support\Str::substr($profileUser->name, 0, 1)) }}
                        </div>
                    @endif
                </div>

                {{-- Name, Pronouns, Stats --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">
                            {{ $profileUser->name }}
                        </h1>
                        @if($isFriend)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">group</span>
                                {{ __('common.status_friends') }}
                            </span>
                        @endif
                    </div>

                    @if($profileUser->pronouns && !$isBlockedBy)
                        <p class="text-sm text-on-surface-variant mt-0.5">{{ $profileUser->pronouns }}</p>
                    @endif

                    {{-- Follower/Following Counts --}}
                    <div class="flex gap-4 mt-2 text-sm text-on-surface-variant">
                        <span>
                            <strong class="text-on-surface">{{ $followerCount }}</strong>
                            {{ Str::plural('follower', $followerCount) }}
                        </span>
                        <span>
                            <strong class="text-on-surface">{{ $followingCount }}</strong>
                            {{ __('common.status_following') }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Action Buttons (only for authenticated non-self viewers) --}}
            @auth
                @unless($isOwnProfile)
                    {{-- Flash feedback --}}
                    @if(session('success'))
                        <div x-data="{ show: true }"
                             x-init="setTimeout(() => { show = false; $el.remove() }, 4000)"
                             x-show="show"
                             x-transition
                             class="flex items-center gap-2 mb-3 px-4 py-2.5 rounded-lg bg-primary/10 text-primary text-sm font-medium">
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                            {{ session('success') }}
                        </div>
                    @endif

                    <div class="flex items-center gap-3 mt-4 pt-4 border-t border-outline-variant/20">
                        @if($isBlockedBy)
                            <p class="text-sm text-on-surface-variant italic">{{ __('profile.content_cannot_interact') }}</p>
                        @elseif($hasBlocked)
                            <button wire:click="unblock"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-error-container text-on-error-container hover:brightness-110 transition-colors">
                                <span class="material-symbols-outlined text-base">lock_open</span>
                                {{ __('common.action_unblock') }}
                            </button>
                        @else
                            @if($isFollowing)
                                <button wire:click="unfollow"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container transition-colors">
                                    <span class="material-symbols-outlined text-base">person_remove</span>
                                    {{ __('common.action_unfollow') }}
                                </button>
                            @else
                                <button wire:click="follow"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                                    {{ __('common.action_follow') }}
                                </button>
                            @endif

                            <button wire:click="block"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-error-container/20 hover:text-error transition-colors">
                                <span class="material-symbols-outlined text-base">block</span>
                                {{ __('common.action_block') }}
                            </button>
                        @endif
                    </div>
                @endunless
            @else
                @unless($isOwnProfile)
                    <div class="flex items-center gap-3 mt-4 pt-4 border-t border-outline-variant/20">
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                            {{ __('profile.action_log_in_to_follow') }}
                        </a>
                    </div>
                @endunless
            @endauth
        </section>

        {{-- If blocked by profile user, show limited info --}}
        @if($isBlockedBy)
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 text-center">
                <span class="material-symbols-outlined text-4xl text-on-surface-variant">lock</span>
                <p class="mt-2 text-on-surface-variant">{{ __('profile.content_profile_not_available') }}</p>
            </section>
        @else
            {{-- Registration CTA for guests --}}
            <x-registration-cta :message="__('profile.guest_nudge_profile')" />

            {{-- Game Systems --}}
            @if(in_array('game_systems', $visibleFields))
                @php
                    $gameSystems = $profileUser->relationLoaded('favoriteGameSystems') ? $profileUser->favoriteGameSystems : collect();
                @endphp
                @if($gameSystems->isNotEmpty())
                    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                            <span class="material-symbols-outlined text-base align-middle mr-1">casino</span>
                            {{ __('games.content_game_systems') }}
                        </h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach($gameSystems as $system)
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-primary/10 text-primary">
                                    {{ $system->name }}
                                </span>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endif

            {{-- Vibes --}}
            @if(in_array('vibes', $visibleFields))
                @php
                    $vibes = $profileUser->relationLoaded('favoriteVibes') ? $profileUser->favoriteVibes : collect();
                @endphp
                @if($vibes->isNotEmpty())
                    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                            <span class="material-symbols-outlined text-base align-middle mr-1">mood</span>
                            {{ __('profile.content_vibes') }}
                        </h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach($vibes as $vibe)
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-tertiary/10 text-tertiary">
                                    {{ $vibe->vibe_preference_value->label() }}
                                </span>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endif

            {{-- Game Sessions --}}
            @if($games->isNotEmpty())
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                        <span class="material-symbols-outlined text-base align-middle mr-1">event_note</span>
                        {{ __('games.content_upcoming_game_sessions') }}
                    </h2>
                    <div class="space-y-2">
                        @foreach($games as $game)
                            <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-high">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-on-surface truncate">{{ $game->name }}</p>
                                    @if($game->gameSystem?->name)
                                        <p class="text-sm text-on-surface-variant">{{ $game->gameSystem->name }}</p>
                                    @endif
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-xs text-on-surface-variant">{{ format_date($game->date_time, 'datetime') }}</span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ $game->visibility === 'public' ? 'bg-secondary-container text-on-secondary-container' : 'bg-tertiary/10 text-tertiary' }}">
                                            {{ __(ucfirst($game->visibility)) }}
                                        </span>
                                        <span class="text-xs text-on-surface-variant">{{ trans_choice('common.content_count_players', $game->participants_count) }}</span>
                                    </div>
                                </div>
                                @can('view', $game)
                                    <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $game->id]) }}"
                                       wire:navigate class="text-sm text-primary hover:underline ml-3 shrink-0">{{ __('common.action_view') }}</a>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Campaigns --}}
            @if($campaigns->isNotEmpty())
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                        <span class="material-symbols-outlined text-base align-middle mr-1">auto_stories</span>
                        {{ __('campaigns.content_campaigns') }}
                    </h2>
                    <div class="space-y-2">
                        @foreach($campaigns as $campaign)
                            <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-high">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-on-surface truncate">{{ $campaign->name }}</p>
                                    @if($campaign->gameSystem?->name)
                                        <p class="text-sm text-on-surface-variant">{{ $campaign->gameSystem->name }}</p>
                                    @endif
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ $campaign->visibility === 'public' ? 'bg-secondary-container text-on-secondary-container' : 'bg-tertiary/10 text-tertiary' }}">
                                            {{ __(ucfirst($campaign->visibility)) }}
                                        </span>
                                        <span class="text-xs text-on-surface-variant">{{ trans_choice('common.content_count_participants', $campaign->participants_count) }}</span>
                                    </div>
                                </div>
                                @can('view', $campaign)
                                    <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}"
                                       wire:navigate class="text-sm text-primary hover:underline ml-3 shrink-0">{{ __('common.action_view') }}</a>
                                @endcan
                            </div>
                        @endforeach>
                    </div>
                </section>
            @endif

            {{-- Teams --}}
            @if(in_array('teams', $visibleFields))
                @php
                    $teamMemberships = $teamMemberships ?? collect();
                @endphp
                @if($teamMemberships->isNotEmpty())
                    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                            <span class="material-symbols-outlined text-base align-middle mr-1">groups</span>
                            {{ __('teams.content_teams') }}
                        </h2>
                        <div class="space-y-2">
                            @foreach($teamMemberships as $membership)
                                <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-high">
                                    <div class="flex items-center gap-3">
                                        <p class="font-medium text-on-surface">{{ $membership->team->name }}</p>
                                        @if($membership->role)
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-secondary/10 text-secondary capitalize">{{ $membership->role }}</span>
                                        @endif
                                    </div>
                                    <a href="{{ route('teams.detail', ['locale' => app()->getLocale(), 'slug' => $membership->team->slug]) }}"
                                       wire:navigate class="text-sm text-primary hover:underline">{{ __('common.action_view') }}</a>
                                </div>
                            @endforeach>
                        </div>
                    </section>
                @endif
            @endif
        @endif
    </div>
</div>
