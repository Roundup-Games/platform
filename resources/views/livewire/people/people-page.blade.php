<div class="py-8">
    <div class="max-w-3xl mx-auto space-y-6">

        {{-- Page Header --}}
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">
                <span class="material-symbols-outlined text-2xl align-middle mr-1" style="font-variation-settings: 'FILL' 1">people</span>
                People
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
                Following
                <span class="ml-1 text-xs {{ $activeTab === 'following' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->followingCount }})</span>
            </button>
            <button wire:click="$set('activeTab', 'followers')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'followers' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                Followers
                <span class="ml-1 text-xs {{ $activeTab === 'followers' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->followersCount }})</span>
            </button>
            <button wire:click="$set('activeTab', 'blocked')"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'blocked' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' }}">
                Blocked
                <span class="ml-1 text-xs {{ $activeTab === 'blocked' ? 'text-primary/70' : 'text-on-surface-variant/60' }}">({{ $this->blockedCount }})</span>
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
                                            Friends
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Unfollow Button --}}
                            <button wire:click="unfollow({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container transition-colors shrink-0">
                                <span class="material-symbols-outlined text-base">person_remove</span>
                                <span wire:loading.remove wire:target="unfollow({{ $user->id }})">Unfollow</span>
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
                        <p class="mt-2 text-on-surface-variant">You're not following anyone yet.</p>
                        <a href="{{ route('discover', ['locale' => app()->getLocale()]) }}"
                           wire:navigate
                           class="inline-flex items-center gap-1.5 mt-3 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors">
                            <span class="material-symbols-outlined text-base">explore</span>
                            Discover people
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
                                            Friends
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
                                    Remove
                                </button>
                            @else
                                {{-- Not mutual: show follow back --}}
                                <button wire:click="followBack({{ $user->id }})"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-primary text-on-primary hover:brightness-110 transition-colors shrink-0">
                                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">person_add</span>
                                    Follow back
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
                        <p class="mt-2 text-on-surface-variant">No followers yet.</p>
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
                                Unblock
                            </button>
                        </div>
                    @endforeach
                    <div class="mt-4">
                        {{ $blocked->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-on-surface-variant">block</span>
                        <p class="mt-2 text-on-surface-variant">You haven't blocked anyone.</p>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
