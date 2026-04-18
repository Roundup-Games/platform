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
                                Friends
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
                            following
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
                            {{-- Viewer is blocked by this user — no actions available --}}
                            <p class="text-sm text-on-surface-variant italic">You cannot interact with this profile.</p>
                        @elseif($hasBlocked)
                            {{-- Viewer has blocked this user — show unblock --}}
                            <button wire:click="unblock"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-error-container text-on-error-container hover:brightness-110 transition-colors">
                                <span class="material-symbols-outlined text-base">lock_open</span>
                                Unblock
                            </button>
                        @else
                            {{-- Follow/Unfollow --}}
                            @if($isFollowing)
                                <button wire:click="unfollow"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container transition-colors">
                                    <span class="material-symbols-outlined text-base">person_remove</span>
                                    Unfollow
                                </button>
                            @else
                                <button wire:click="follow"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                                    Follow
                                </button>
                            @endif

                            {{-- Block --}}
                            <button wire:click="block"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-error-container/20 hover:text-error transition-colors">
                                <span class="material-symbols-outlined text-base">block</span>
                                Block
                            </button>
                        @endif
                    </div>
                @endunless
            @else
                {{-- Unauthenticated viewer — login prompt --}}
                @unless($isOwnProfile)
                    <div class="flex items-center gap-3 mt-4 pt-4 border-t border-outline-variant/20">
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                            Log in to follow
                        </a>
                    </div>
                @endunless
            @endauth
        </section>

        {{-- If blocked by profile user, show limited info --}}
        @if($isBlockedBy)
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 text-center">
                <span class="material-symbols-outlined text-4xl text-on-surface-variant">lock</span>
                <p class="mt-2 text-on-surface-variant">This profile is not available.</p>
            </section>
        @else
            {{-- Game Systems --}}
            @if(in_array('game_systems', $visibleFields))
                @php
                    $gameSystems = $profileUser->relationLoaded('favoriteGameSystems') ? $profileUser->favoriteGameSystems : collect();
                @endphp
                @if($gameSystems->isNotEmpty())
                    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                            <span class="material-symbols-outlined text-base align-middle mr-1">casino</span>
                            Game Systems
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
                            Vibes
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

            {{-- Campaigns --}}
            @if(in_array('campaigns', $visibleFields))
                @php
                    $campaigns = $profileUser->relationLoaded('ownedCampaigns') ? $profileUser->ownedCampaigns : collect();
                @endphp
                @if($campaigns->isNotEmpty())
                    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-3">
                            <span class="material-symbols-outlined text-base align-middle mr-1">auto_stories</span>
                            Campaigns
                        </h2>
                        <div class="space-y-2">
                            @foreach($campaigns as $campaign)
                                <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-high">
                                    <div>
                                        <p class="font-medium text-on-surface">{{ $campaign->name }}</p>
                                        @if($campaign->gameSystem?->name)
                                            <p class="text-sm text-on-surface-variant">{{ $campaign->gameSystem->name }}</p>
                                        @endif
                                    </div>
                                    @can('view', $campaign)
                                        <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}"
                                           class="text-sm text-primary hover:underline">View</a>
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
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
                            Teams
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
                                       class="text-sm text-primary hover:underline">View</a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endif
        @endif
    </div>
</div>
