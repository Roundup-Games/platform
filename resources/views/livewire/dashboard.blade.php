@section('title', __('profile.content_dashboard'))

<div class="py-4">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Welcome Section --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                        {{ __('common.content_welcome_back_name', ['name' => Auth::user()->name]) }}
                    </h2>
                    <p class="mt-1 text-on-surface-variant">
                        {{ __("events.content_you_re_logged_in_to") }}
                    </p>
                </div>
                @if($unreadNotificationsCount > 0)
                    <a href="{{ route('notifications.index') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary text-sm font-medium hover:bg-primary/20 transition-colors">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">notifications</span>
                        {{ $unreadNotificationsCount }} {{ __('profile.dashboard_stats_unread_notifications') }}
                    </a>
                @endif
            </div>
        </div>

        {{-- Stats Grid --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {{-- Upcoming Sessions --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">calendar_month</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-2xl font-heading font-bold text-on-surface">{{ $upcomingSessionsCount }}</p>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_upcoming_sessions') }}</p>
                    </div>
                </div>
            </div>

            {{-- Active Games --}}
            <a href="{{ route('games.index') }}" wire:navigate
               class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 hover:shadow-ambient-md transition-all group block">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">stadium</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-2xl font-heading font-bold text-on-surface group-hover:text-primary transition-colors">{{ $activeGamesCount }}</p>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_active_games') }}</p>
                    </div>
                </div>
            </a>

            {{-- Active Campaigns --}}
            <a href="{{ route('campaigns.index') }}" wire:navigate
               class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 hover:shadow-ambient-md transition-all group block">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">campaign</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-2xl font-heading font-bold text-on-surface group-hover:text-primary transition-colors">{{ $activeCampaignsCount }}</p>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_active_campaigns') }}</p>
                    </div>
                </div>
            </a>

            {{-- Pending Invitations --}}
            @php
                $invitationsRoute = $pendingInvitationsCount > 0 ? route('games.index') : route('games.index');
            @endphp
            <a href="{{ $invitationsRoute }}" wire:navigate
               class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 hover:shadow-ambient-md transition-all group block">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">mail</span>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-2xl font-heading font-bold text-on-surface group-hover:text-primary transition-colors">{{ $pendingInvitationsCount }}</p>
                            @if($pendingInvitationsCount > 0)
                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-error text-on-error text-xs font-bold">
                                    {{ $pendingInvitationsCount }}
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_pending_invitations') }}</p>
                    </div>
                </div>
            </a>

            {{-- Followers --}}
            <a href="{{ route('people') }}" wire:navigate
               class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 hover:shadow-ambient-md transition-all group block">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">group</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-2xl font-heading font-bold text-on-surface group-hover:text-primary transition-colors">{{ $followersCount }}</p>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_followers') }}</p>
                    </div>
                </div>
            </a>

            {{-- Following --}}
            <a href="{{ route('people') }}" wire:navigate
               class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 hover:shadow-ambient-md transition-all group block">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">person_add</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-2xl font-heading font-bold text-on-surface group-hover:text-primary transition-colors">{{ $followingCount }}</p>
                        <p class="text-sm text-on-surface-variant truncate">{{ __('profile.dashboard_stats_following') }}</p>
                    </div>
                </div>
            </a>
        </div>

        {{-- Games This Week Engagement Card --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">event_note</span>
                    {{ __('attendance.dashboard_games_this_week') }}
                </h3>
                @if($gamesThisWeekCount > 0)
                    <span class="text-2xl font-heading font-bold text-primary">{{ $gamesThisWeekCount }}</span>
                @endif
            </div>

            @if($gamesThisWeekCount > 0)
                <div class="space-y-3">
                    {{-- Summary stats --}}
                    <div class="flex items-center gap-4 text-sm">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                            {{ $gamesThisWeekSummary['attended'] }} {{ __('attendance.dashboard_attended') }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            {{ $gamesThisWeekSummary['pending'] }} {{ __('attendance.dashboard_pending') }}
                        </span>
                        <span class="text-on-surface-variant">
                            {{ $gamesThisWeekSummary['total'] }} {{ __('attendance.dashboard_total') }}
                        </span>
                    </div>

                    {{-- Game list --}}
                    <div class="space-y-2">
                        @foreach($gamesThisWeek as $game)
                            <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                               class="flex items-center justify-between p-3 rounded-lg hover:bg-surface-container-low transition-colors group">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate">
                                        {{ $game->name }}
                                    </p>
                                    <p class="text-xs text-on-surface-variant">
                                        {{ $game->date_time->format('D, M j · g:i A') }}
                                    </p>
                                </div>
                                @if($game->owner_id === Auth::id())
                                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary font-medium">{{ __('attendance.dashboard_hosting') }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="text-center py-6">
                    <span class="material-symbols-outlined text-on-surface-variant text-4xl mb-2" style="font-variation-settings: 'FILL' 0">event_available</span>
                    <p class="text-on-surface-variant text-sm mb-3">{{ __('attendance.dashboard_no_games_this_week') }}</p>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">explore</span>
                        {{ __('attendance.dashboard_find_next_game') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- New Recaps Card --}}
        @if($newRecaps->count() > 0)
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">auto_stories</span>
                    {{ __('attendance.dashboard_new_recaps') }}
                </h3>
                <div class="space-y-2">
                    @foreach($newRecaps as $game)
                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                           class="flex items-center justify-between p-3 rounded-lg hover:bg-surface-container-low transition-colors group">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate">
                                    {{ $game->name }}
                                </p>
                                <p class="text-xs text-on-surface-variant">
                                    {{ __('attendance.dashboard_recap_by') }} {{ $game->owner?->name }}
                                </p>
                            </div>
                            <span class="material-symbols-outlined text-primary text-lg ml-2" style="font-variation-settings: 'FILL' 1">arrow_forward</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- GM Stats Section --}}
        @if(Auth::user()?->isGM() && $gmAverageRating !== null)
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <h3 class="font-heading text-lg font-semibold text-on-surface mb-4">
                    <span class="material-symbols-outlined text-primary align-middle mr-1" style="font-variation-settings: 'FILL' 1">casino</span>
                    {{ __('profile.dashboard_gm_overview') }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Rating --}}
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary text-xl" style="font-variation-settings: 'FILL' 1">star</span>
                        <div>
                            <p class="text-lg font-heading font-bold text-on-surface">
                                {{ number_format($gmAverageRating, 1) }}
                                <span class="text-sm font-normal text-on-surface-variant">/ 5</span>
                            </p>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_stats_gm_rating') }}</p>
                        </div>
                    </div>

                    {{-- Review Count --}}
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary text-xl" style="font-variation-settings: 'FILL' 1">rate_review</span>
                        <div>
                            <p class="text-lg font-heading font-bold text-on-surface">{{ $gmReviewCount }}</p>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_stats_gm_reviews') }}</p>
                        </div>
                    </div>

                    {{-- Upcoming GM Sessions --}}
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary text-xl" style="font-variation-settings: 'FILL' 1">event</span>
                        <div>
                            <p class="text-lg font-heading font-bold text-on-surface">{{ $gmUpcomingSessionsCount }}</p>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_stats_gm_upcoming') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Activity Timeline --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h3 class="font-heading text-lg font-semibold text-on-surface mb-4">
                <span class="material-symbols-outlined text-primary align-middle mr-1" style="font-variation-settings: 'FILL' 1">timeline</span>
                {{ __('profile.dashboard_recent_activity') }}
            </h3>

            @if($recentActivity->count() > 0)
                <div class="space-y-3">
                    @foreach($recentActivity as $activity)
                        @php
                            $activityType = $activity->event_type;
                            $subjectName = $activity->properties['name'] ?? $activity->properties['title'] ?? null;
                        @endphp
                        <div class="flex items-start gap-3 py-2 {{ !$loop->last ? 'border-b border-outline-variant/30' : '' }}">
                            <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-primary text-lg" style="font-variation-settings: 'FILL' 1">
                                    {{ $activityType->icon() }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-on-surface text-sm">
                                    {{ $activityType->label() }}
                                    @if($subjectName)
                                        <span class="font-semibold">{{ $subjectName }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-on-surface-variant mt-0.5">
                                    {{ $activity->created_at->diffForHumans() }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12">
                    <span class="material-symbols-outlined text-on-surface-variant text-5xl mb-3" style="font-variation-settings: 'FILL' 0">history</span>
                    <p class="text-on-surface-variant font-medium">{{ __('common.activity_no_results') }}</p>
                    <p class="text-sm text-on-surface-variant mt-1">{{ __('common.activity_start_cta') }}</p>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">explore</span>
                        {{ __('discovery.action_discover') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Quick Actions --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <a href="{{ route('games.index') }}" wire:navigate
               class="bg-surface-container-lowest p-5 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">stadium</span>
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors text-sm">{{ __('profile.dashboard_card_my_games') }}</h3>
                        <p class="text-xs text-on-surface-variant">{{ __('profile.dashboard_card_my_games_desc') }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('campaigns.index') }}" wire:navigate
               class="bg-surface-container-lowest p-5 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">campaign</span>
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors text-sm">{{ __('profile.dashboard_card_my_campaigns') }}</h3>
                        <p class="text-xs text-on-surface-variant">{{ __('profile.dashboard_card_my_campaigns_desc') }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('people') }}" wire:navigate
               class="bg-surface-container-lowest p-5 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">people</span>
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors text-sm">{{ __('people.content_people') }}</h3>
                        <p class="text-xs text-on-surface-variant">{{ __('people.content_manage_following_followers_blocked') }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('discover') }}" wire:navigate
               class="bg-surface-container-lowest p-5 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">explore</span>
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors text-sm">{{ __('discovery.action_discover') }}</h3>
                        <p class="text-xs text-on-surface-variant">{{ __('discovery.content_find_games_near_you') }}</p>
                    </div>
                </div>
            </a>

            @if(Auth::user()?->isGM())
                <a href="{{ route('gm.workspace') }}" wire:navigate
                   class="bg-surface-container-lowest p-5 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">casino</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors text-sm">{{ __('profile.dashboard_card_gm_workspace') }}</h3>
                            <p class="text-xs text-on-surface-variant">{{ __('profile.dashboard_card_gm_workspace_desc') }}</p>
                        </div>
                    </div>
                </a>
            @endif
        </div>

    </div>
</div>
