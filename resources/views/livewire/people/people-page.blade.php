<div class="py-8">
    <div class="max-w-3xl mx-auto space-y-6">

        {{-- Page Header --}}
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">
                <span class="material-symbols-outlined text-2xl align-middle mr-1" style="font-variation-settings: 'FILL' 1">people</span>
                {{ __('people.content_people') }}
            </h1>
        </div>

        {{-- Flash Feedback --}}
        @if(session('success'))
            <div x-data="{ show: true }"
                 x-init="setTimeout(() => { show = false; $el.remove() }, 4000)"
                 x-show="show"
                 x-transition
                 class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary/10 text-primary text-sm font-medium">
                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                {{ session('success') }}
            </div>
        @endif

        {{-- Tabs --}}
        <div class="flex border-b border-outline-variant/20">
            <button wire:click="$set('activeTab', 'following')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'following' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                {{ __('people.content_following') }}
                <span class="ml-1 text-xs {{ $activeTab === 'following' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->followingCount }})</span>
            </button>
            <button wire:click="$set('activeTab', 'followers')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'followers' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                {{ __('people.content_followers') }}
                <span class="ml-1 text-xs {{ $activeTab === 'followers' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->followersCount }})</span>
            </button>
            <button wire:click="$set('activeTab', 'blocked')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'blocked' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                {{ __('people.content_blocked_tab') }}
                <span class="ml-1 text-xs {{ $activeTab === 'blocked' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->blockedCount }})</span>
            </button>
            <button wire:click="$set('activeTab', 'nearby')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'nearby' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                <span class="material-symbols-outlined text-sm align-middle mr-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'nearby' ? '1' : '0' }}">near_me</span>
                {{ __('people.tab_nearby') }}
                <span class="ml-1 text-xs {{ $activeTab === 'nearby' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->nearbyCount }})</span>
            </button>
        </div>

        {{-- Tab Content --}}
        <div class="space-y-3">
            {{-- Following Tab --}}
            @if($activeTab === 'following')
                @php $followings = $this->followingUsers @endphp
                @if($followings->count() > 0)
                    @foreach($followings as $rel)
                        @php
                            $user = $rel->related;
                            $isMutual = $this->authUser->isFollowedBy($user);
                        @endphp
                        <div class="flex items-center gap-4 p-4 bg-surface-container-lowest rounded-xl shadow-ambient"
                             wire:key="following-{{ $user->id }}">
                            {{-- User Link + Badge --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <x-user-link :user="$user" avatar-size="w-12 h-12" :truncate="true" />
                                    @if($isMutual)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary shrink-0">
                                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">group</span>
                                            {{ __('people.content_friends') }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Unfollow Button --}}
                            <button wire:click="unfollow({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container transition-colors shrink-0">
                                <span class="material-symbols-outlined text-base">person_remove</span>
                                <span wire:loading.remove wire:target="unfollow({{ $user->id }})">{{ __('common.action_unfollow') }}</span>
                                <span wire:loading wire:target="unfollow({{ $user->id }})">...</span>
                            </button>
                        </div>
                    @endforeach
                    <div class="mt-4">
                        {{ $followings->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">person_add</span>
                        <p class="mt-2 text-on-surface-variant">{{ __('people.content_not_following_anyone_yet') }}</p>
                        <a href="{{ route('discover', ['locale' => app()->getLocale()]) }}"
                           wire:navigate
                           class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                            <span class="material-symbols-outlined text-base">explore</span>
                            {{ __('people.action_discover_people') }}
                        </a>
                    </div>
                @endif

            {{-- Followers Tab --}}
            @elseif($activeTab === 'followers')
                @php $followers = $this->followerUsers @endphp
                @if($followers->count() > 0)
                    @foreach($followers as $rel)
                        @php
                            $user = $rel->user;
                            $isFollowingBack = $this->authUser->isFollowing($user);
                        @endphp
                        <div class="flex items-center gap-4 p-4 bg-surface-container-lowest rounded-xl shadow-ambient"
                             wire:key="follower-{{ $user->id }}">
                            {{-- User Link + Badge --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <x-user-link :user="$user" avatar-size="w-12 h-12" :truncate="true" />
                                    @if($isFollowingBack)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary shrink-0">
                                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">group</span>
                                            {{ __('people.content_friends') }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Action Button --}}
                            @if($isFollowingBack)
                                {{-- Mutual: show remove --}}
                                <button wire:click="removeFollower({{ $user->id }})"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container transition-colors shrink-0">
                                    <span class="material-symbols-outlined text-base">person_remove</span>
                                    {{ __('common.action_remove') }}
                                </button>
                            @else
                                {{-- Not mutual: show follow back --}}
                                <button wire:click="followBack({{ $user->id }})"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors shrink-0">
                                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                                    {{ __('people.action_follow_back') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                    <div class="mt-4">
                        {{ $followers->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">group</span>
                        <p class="mt-2 text-on-surface-variant">{{ __('people.content_no_followers_yet') }}</p>
                    </div>
                @endif

            {{-- Blocked Tab --}}
            @elseif($activeTab === 'blocked')
                @php $blocked = $this->blockedUsers @endphp
                @if($blocked->count() > 0)
                    @foreach($blocked as $rel)
                        @php $user = $rel->related @endphp
                        <div class="flex items-center gap-4 p-4 bg-surface-container-lowest rounded-xl shadow-ambient"
                             wire:key="blocked-{{ $user->id }}">
                            {{-- User Link --}}
                            <div class="flex-1 min-w-0">
                                <x-user-link :user="$user" avatar-size="w-12 h-12" :truncate="true" />
                            </div>

                            {{-- Unblock Button --}}
                            <button wire:click="unblock({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-error-container text-on-error-container hover:brightness-110 transition-colors shrink-0">
                                <span class="material-symbols-outlined text-base">lock_open</span>
                                {{ __('common.action_unblock') }}
                            </button>
                        </div>
                    @endforeach
                    <div class="mt-4">
                        {{ $blocked->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">block</span>
                        <p class="mt-2 text-on-surface-variant">{{ __('people.content_you_haven_t_blocked_anyone') }}</p>
                    </div>
                @endif

            {{-- Nearby Tab --}}
            @elseif($activeTab === 'nearby')
                @php
                    $nearby = $this->nearbyUsers;
                    $nearbyResults = $nearby['results'] ?? null;
                    $noLocation = $nearby['noLocation'] ?? false;
                @endphp

                @if($noLocation)
                    {{-- No location set --}}
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">location_off</span>
                        <p class="mt-2 text-on-surface-variant">{{ __('people.nearby_no_location') }}</p>
                        <a href="{{ route('profile.edit', ['locale' => app()->getLocale()]) }}"
                           wire:navigate
                           class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                            <span class="material-symbols-outlined text-base">edit_location</span>
                            {{ __('people.nearby_action_set_location') }}
                        </a>
                    </div>
                @elseif($nearbyResults && $nearbyResults->count() > 0)
                    @php
                        $nearbyUserMap = \App\Models\User::with('media')
                            ->whereIn('id', $nearbyResults->pluck('user_id')->unique()->all())
                            ->get()
                            ->keyBy('id');
                    @endphp
                    @foreach($nearbyResults as $result)
                        @php
                            $user = $nearbyUserMap[$result['user_id']] ?? null;
                            if (! $user) { continue; }
                            $score = $result['compatibility_score'];
                            $tier = $result['tier'];
                            $distanceKm = $result['distance_km'];
                            $reasons = $result['match_reasons'] ?? [];
                            $scorePct = round($score * 100);

                            // Determine compatibility color
                            if ($scorePct >= 60) {
                                $barColor = 'bg-green-500';
                                $barBg = 'bg-green-500/10';
                                $scoreTextColor = 'text-green-600';
                            } elseif ($scorePct >= 30) {
                                $barColor = 'bg-amber-500';
                                $barBg = 'bg-amber-500/10';
                                $scoreTextColor = 'text-amber-600';
                            } else {
                                $barColor = 'bg-surface-container-highest';
                                $barBg = 'bg-surface-container-highest/20';
                                $scoreTextColor = 'text-on-surface-variant';
                            }

                            // Format distance badge
                            if ($tier === 1) {
                                $distanceLabel = __('people.nearby_in_your_area');
                            } else {
                                $roundedKm = max(5, round($distanceKm / 5) * 5);
                                $distanceLabel = __('people.nearby_distance_label', ['distance' => $roundedKm]);
                            }
                        @endphp

                        <div class="p-4 bg-surface-container-lowest rounded-xl shadow-ambient space-y-3"
                             wire:key="nearby-{{ $user->id }}">

                            {{-- Header: Avatar + Name + Distance Badge --}}
                            <div class="flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <x-user-link :user="$user" avatar-size="w-10 h-10" :truncate="true" />
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium {{ $tier === 1 ? 'bg-primary/10 text-primary' : 'bg-on-surface-variant/10 text-on-surface-variant' }} shrink-0">
                                            <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' {{ $tier === 1 ? '1' : '0' }}">location_on</span>
                                            {{ $distanceLabel }}
                                        </span>
                                    </div>
                                </div>

                                {{-- Follow Button --}}
                                <button wire:click="followFromNearby({{ $user->id }})"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors shrink-0">
                                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                                    <span wire:loading.remove wire:target="followFromNearby({{ $user->id }})">{{ __('common.action_follow') }}</span>
                                    <span wire:loading wire:target="followFromNearby({{ $user->id }})">...</span>
                                </button>
                            </div>

                            {{-- Compatibility Bar --}}
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 rounded-full {{ $barBg }}">
                                    <div class="h-1.5 rounded-full {{ $barColor }} transition-all"
                                         style="width: {{ max($scorePct, 3) }}%"></div>
                                </div>
                                <span class="text-xs font-medium {{ $scoreTextColor }} shrink-0 tabular-nums">{{ __('people.nearby_compatibility', ['percent' => $scorePct]) }}</span>
                            </div>

                            {{-- Match Reasons --}}
                            @if(!empty($reasons))
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($reasons as $reason)
                                        @php
                                            $reasonIcon = match($reason) {
                                                'shared_game_systems' => 'casino',
                                                'shared_vibes' => 'mood',
                                                'shared_teams' => 'groups',
                                                'mutual_follow' => 'group',
                                                'Nearby' => 'location_on',
                                                default => 'person',
                                            };
                                            $reasonLabel = match($reason) {
                                                'shared_game_systems' => __('people.nearby_reason_shared_game_systems'),
                                                'shared_vibes' => __('people.nearby_reason_shared_vibes'),
                                                'shared_teams' => __('people.nearby_reason_shared_teams'),
                                                'mutual_follow' => __('people.nearby_reason_mutual_follow'),
                                                'Nearby' => __('people.nearby_reason_nearby'),
                                                default => $reason,
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-surface-container-high text-on-surface-variant">
                                            <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' 0">{{ $reasonIcon }}</span>
                                            {{ $reasonLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    {{-- Pagination --}}
                    @if($nearbyResults->hasMorePages())
                        <div class="mt-4">
                            {{ $nearbyResults->links() }}
                        </div>
                    @endif
                @else
                    {{-- No results --}}
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">explore_off</span>
                        <p class="mt-2 text-on-surface-variant">{{ __('people.nearby_no_results') }}</p>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
