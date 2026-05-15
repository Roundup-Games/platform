@section('title', __('profile.content_dashboard'))

<div class="py-4">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Smart Prompt Section --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8" role="status" aria-live="polite">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    @php
                        $promptIcon = match($smartPrompt['type']) {
                            'pending_invitations' => 'mail',
                            'upcoming_session' => 'schedule',
                            'just_completed' => 'auto_stories',
                            'empty_week' => 'event_available',
                            'new_follower' => 'person_add',
                            default => 'auto_awesome',
                        };
                        $promptIconFilled = in_array($smartPrompt['type'], ['pending_invitations', 'upcoming_session']);
                    @endphp
                    <div class="flex items-start gap-3">
                        <span aria-hidden="true" class="material-symbols-outlined text-primary text-2xl mt-0.5 flex-shrink-0"
                              style="font-variation-settings: 'FILL' {{ $promptIconFilled ? '1' : '0' }}">
                            {{ $promptIcon }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-on-surface text-base sm:text-lg font-medium leading-relaxed">
                                {{ $smartPrompt['message'] }}
                            </p>
                            @if($smartPrompt['action_url'] && $smartPrompt['action_label'])
                                <a href="{{ $smartPrompt['action_url'] }}" wire:navigate
                                   class="inline-flex items-center gap-1.5 mt-3 text-sm font-semibold text-primary hover:underline">
                                    {{ $smartPrompt['action_label'] }}
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
                @if($unreadNotificationsCount > 0)
                    <a href="{{ route('notifications.index') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary text-sm font-medium hover:bg-primary/20 transition-colors flex-shrink-0">
                        <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">notifications</span>
                        {{ $unreadNotificationsCount }} {{ __('profile.dashboard_stats_unread_notifications') }}
                    </a>
                @endif
            </div>
        </div>

        {{-- Your Week Section --}}
        @php
            $weekGames = $gamesThisWeek;
            $hasGames = $weekGames->count() > 0;

            // Build day-by-day structure for the 7-column layout
            $startOfWeek = now()->startOfWeek();
            $days = collect(range(0, 6))->map(function ($offset) use ($startOfWeek, $weekGames) {
                $date = $startOfWeek->copy()->addDays($offset);
                $dayGames = $weekGames->filter(fn ($g) => $g->date_time->isSameDay($date));
                return [
                    'date' => $date,
                    'day_name' => app()->getLocale() === 'de' ? $date->isoFormat('dd') : $date->format('D'),
                    'day_label' => app()->getLocale() === 'de' ? $date->isoFormat('D. MMM') : $date->format('M j'),
                    'is_today' => $date->isToday(),
                    'is_past' => $date->isBefore(now()->startOfDay()),
                    'games' => $dayGames,
                ];
            });
        @endphp

        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2">
                    <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">date_range</span>
                    {{ __('profile.dashboard_your_week') }}
                </h3>
                @if($hasGames)
                    <span class="text-sm text-on-surface-variant">
                        {{ $weekGames->count() }} {{ $weekGames->count() === 1 ? __('games.content_game') : __('games.content_games') }}
                    </span>
                @endif
            </div>

            @if($hasGames)
                {{-- Desktop: 7-column grid --}}
                <div class="hidden sm:grid sm:grid-cols-7 gap-2">
                    @foreach($days as $day)
                        <div class="min-w-0">
                            {{-- Day header --}}
                            <div class="text-center mb-2 pb-1.5 {{ $day['is_today'] ? 'border-b-2 border-primary' : 'border-b border-outline-variant/30' }}">
                                <p class="text-xs font-medium {{ $day['is_today'] ? 'text-primary' : 'text-on-surface-variant' }}">
                                    {{ $day['day_name'] }}
                                </p>
                                <p class="text-xs {{ $day['is_today'] ? 'text-primary font-semibold' : 'text-on-surface-variant' }}">
                                    {{ $day['day_label'] }}
                                </p>
                            </div>
                            {{-- Day games --}}
                            <div class="space-y-1.5">
                                @foreach($day['games'] as $game)
                                    @php
                                        $isHost = $game->owner_id === Auth::id();
                                        $isPast = $day['is_past'];
                                        $playerCount = $game->participants_count ?? $game->participants->count();
                                        $maxPlayers = $game->max_players;
                                    @endphp
                                    <a href="{{ route('games.show', $game->id) }}" wire:navigate
                                       class="block p-2 rounded-lg {{ $isPast ? 'bg-surface-container-low opacity-60' : 'bg-primary/5 hover:bg-primary/10' }} transition-colors group">
                                        <p class="text-xs font-medium {{ $isPast ? 'text-on-surface-variant' : 'text-on-surface group-hover:text-primary' }} truncate leading-tight">
                                            @if($isHost)<span class="sr-only">{{ __('profile.dashboard_hosting_indicator') }}</span><span aria-hidden="true">★ </span>@endif{{ Str::limit($game->name, 18) }}
                                        </p>
                                        <p class="text-[10px] {{ $isPast ? 'text-on-surface-variant/70' : 'text-on-surface-variant' }} mt-0.5">
                                            {{ app()->getLocale() === 'de' ? $game->date_time->isoFormat('HH:mm') : $game->date_time->format('g:i A') }}
                                        </p>
                                        @if($maxPlayers)
                                            <p class="text-[10px] text-on-surface-variant mt-0.5">
                                                {{ $playerCount }}/{{ $maxPlayers }}
                                                <span class="material-symbols-outlined text-[10px] align-middle" aria-hidden="true">group</span>
                                            </p>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Mobile: Stacked day list --}}
                <div class="sm:hidden space-y-3">
                    @foreach($days as $day)
                        @if($day['games']->count() > 0)
                            <div>
                                <div class="flex items-center gap-2 mb-1.5">
                                    <p class="text-xs font-semibold {{ $day['is_today'] ? 'text-primary' : 'text-on-surface-variant' }}">
                                        {{ $day['day_name'] }} {{ $day['day_label'] }}
                                    </p>
                                    @if($day['is_today'])
                                        <span class="text-[10px] font-semibold text-primary bg-primary/10 px-1.5 py-0.5 rounded-full">
                                            {{ __('profile.dashboard_your_week_today') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="space-y-1.5">
                                    @foreach($day['games'] as $game)
                                        @php
                                            $isHost = $game->owner_id === Auth::id();
                                            $isPast = $day['is_past'];
                                        @endphp
                                        <a href="{{ route('games.show', $game->id) }}" wire:navigate
                                           class="flex items-center justify-between p-3 rounded-lg {{ $isPast ? 'bg-surface-container-low opacity-60' : 'bg-primary/5 hover:bg-primary/10' }} transition-colors group">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium {{ $isPast ? 'text-on-surface-variant' : 'text-on-surface group-hover:text-primary' }} truncate">
                                                    {{ $game->name }}
                                                </p>
                                                <p class="text-xs text-on-surface-variant mt-0.5">
                                                    {{ app()->getLocale() === 'de' ? $game->date_time->isoFormat('HH:mm') : $game->date_time->format('g:i A') }}
                                                    @if($game->campaign)
                                                        <span class="inline-flex items-center gap-0.5 ml-1 text-primary/70">
                                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">campaign</span>
                                                            {{ $game->campaign->name }}
                                                        </span>
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-2 ml-2 flex-shrink-0">
                                                @if($isHost)
                                                    <span class="text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary font-medium">
                                                        {{ __('attendance.dashboard_hosting') }}
                                                    </span>
                                                @endif
                                                <span class="material-symbols-outlined text-on-surface-variant text-lg" style="font-variation-settings: 'FILL' 0" aria-hidden="true">chevron_right</span>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                {{-- Empty week state --}}
                <div class="text-center py-8">
                    <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-4xl mb-2" style="font-variation-settings: 'FILL' 0">event_available</span>
                    <p class="text-on-surface-variant text-sm mb-3">{{ __('profile.dashboard_your_week_empty') }}</p>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">explore</span>
                        {{ __('profile.dashboard_your_week_find_game') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Opportunities Section --}}
        @php
            $opportunityItems = collect(array_merge($opportunities['games'] ?? [], $opportunities['campaigns'] ?? []));
            $hasOpportunities = $opportunityItems->count() > 0;
        @endphp

        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
                <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">recommend</span>
                {{ __('profile.dashboard_opportunities_heading') }}
            </h3>

            @if($hasOpportunities)
                {{-- Horizontal scrollable card row --}}
                <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory" role="list">
                    @foreach($opportunityItems as $item)
                        @php
                            $routeName = ($item['entity_type'] ?? 'game') === 'campaign' ? 'campaigns.show' : 'games.show';
                            $spotsAvailable = $item['spots_available'] ?? null;
                            $spotsText = $spotsAvailable !== null
                                ? trans_choice('profile.dashboard_opportunities_spots_available', $spotsAvailable, ['count' => $spotsAvailable])
                                : null;
                            $dateText = ($item['entity_type'] ?? 'game') === 'campaign'
                                ? ($item['recurrence'] ? __("campaigns.content_{$item['recurrence']}") : __('profile.dashboard_opportunities_recurring'))
                                : ($item['date_time'] ? (app()->getLocale() === 'de' ? \Carbon\Carbon::parse($item['date_time'])->isoFormat('D. MMM, HH:mm') : \Carbon\Carbon::parse($item['date_time'])->format('M j, g:i A')) : null);
                            $distanceText = $item['distance_km'] !== null
                                ? __('profile.dashboard_opportunities_km_away', ['count' => $item['distance_km']])
                                : null;
                        @endphp
                        <a href="{{ route($routeName, $item['entity_id']) }}" wire:navigate
                           class="flex-shrink-0 w-64 sm:w-72 snap-start bg-surface-container-low rounded-xl border border-outline-variant/30 hover:border-primary/40 hover:shadow-ambient-md transition-all p-4 group" role="listitem">
                            <div class="min-w-0">
                                {{-- System badge --}}
                                @if($item['game_system_name'])
                                    <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary mb-2">
                                        {{ $item['game_system_name'] }}
                                    </span>
                                @endif

                                {{-- Name --}}
                                <p class="text-sm font-semibold text-on-surface group-hover:text-primary transition-colors truncate">
                                    {{ $item['entity_name'] }}
                                </p>

                                {{-- Date or recurrence --}}
                                @if($dateText)
                                    <p class="text-xs text-on-surface-variant mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                        {{ $dateText }}
                                    </p>
                                @endif

                                {{-- Spots + distance row --}}
                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                    @if($spotsText)
                                        <span class="inline-flex items-center gap-0.5 text-[11px] text-on-surface-variant">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                                            {{ $spotsText }}
                                        </span>
                                    @endif
                                    @if($distanceText)
                                        <span class="inline-flex items-center gap-0.5 text-[11px] text-on-surface-variant">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                                            {{ $distanceText }}
                                        </span>
                                    @endif
                                </div>

                                {{-- Owner name --}}
                                @if($item['owner_name'])
                                    <p class="text-[11px] text-on-surface-variant mt-1.5 truncate">
                                        {{ $item['owner_name'] }}
                                    </p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                {{-- Empty state --}}
                <div class="text-center py-8">
                    <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-4xl mb-2" style="font-variation-settings: 'FILL' 0">search_off</span>
                    <p class="text-on-surface-variant text-sm mb-3">{{ __('profile.dashboard_opportunities_empty') }}</p>
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">add_circle</span>
                        {{ __('profile.dashboard_opportunities_create_cta') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Contributions Section --}}
        @php
            $contribs = $contributions ?? [];
            $isGM = Auth::user()?->isGM();
            $hasAnyContributions = (
                ($contribs['hosted']['count'] ?? 0) > 0 ||
                ($contribs['played']['count'] ?? 0) > 0 ||
                ($contribs['campaigns'] !== null) ||
                ($contribs['recaps_written'] ?? 0) > 0 ||
                ($contribs['reviews_given'] ?? 0) > 0 ||
                ($contribs['followers'] ?? 0) > 0 ||
                ($isGM && ($contribs['hosted']['count'] ?? 0) > 0)
            );
        @endphp

        @if($hasAnyContributions)
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
                    <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">volunteer_activism</span>
                    {{ __('profile.dashboard_contributions_heading') }}
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    {{-- Games hosted --}}
                    @if(($contribs['hosted']['count'] ?? 0) > 0)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">stadium</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ trans_choice('profile.dashboard_contributions_hosted', $contribs['hosted']['count']) }}
                                </p>
                                @if(($contribs['hosted']['hours'] ?? 0) > 0)
                                    <p class="text-xs text-on-surface-variant mt-0.5">
                                        {{ trans_choice('profile.dashboard_contributions_hosted_detail', $contribs['hosted']['unique_players'] ?? 0, [
                                            'hours' => round($contribs['hosted']['hours']),
                                            'players' => $contribs['hosted']['unique_players'] ?? 0,
                                        ]) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Games played --}}
                    @if(($contribs['played']['count'] ?? 0) > 0)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">casino</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ trans_choice('profile.dashboard_contributions_played', $contribs['played']['count']) }}
                                </p>
                                @if(($contribs['played']['system_count'] ?? 0) > 0)
                                    <p class="text-xs text-on-surface-variant mt-0.5">
                                        {{ trans_choice('profile.dashboard_contributions_played_detail', $contribs['played']['system_count']) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Longest campaign --}}
                    @if($contribs['campaigns'] !== null)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">campaign</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ __('profile.dashboard_contributions_campaign', ['name' => $contribs['campaigns']['name']]) }}
                                </p>
                                <p class="text-xs text-on-surface-variant mt-0.5">
                                    {{ trans_choice('profile.dashboard_contributions_campaign_detail', $contribs['campaigns']['session_count']) }}
                                </p>
                            </div>
                        </div>
                    @endif

                    {{-- Recaps written --}}
                    @if(($contribs['recaps_written'] ?? 0) > 0)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">auto_stories</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ trans_choice('profile.dashboard_contributions_recaps', $contribs['recaps_written']) }}
                                </p>
                            </div>
                        </div>
                    @endif

                    {{-- Reviews given --}}
                    @if(($contribs['reviews_given'] ?? 0) > 0)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">rate_review</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ trans_choice('profile.dashboard_contributions_reviews', $contribs['reviews_given']) }}
                                </p>
                            </div>
                        </div>
                    @endif

                    {{-- Followers --}}
                    @if(($contribs['followers'] ?? 0) > 0)
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">group</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ trans_choice('profile.dashboard_contributions_followers', $contribs['followers']) }}
                                </p>
                            </div>
                        </div>
                    @endif

                    {{-- GM rating (GM-only card) --}}
                    @if($isGM && ($contribs['hosted']['count'] ?? 0) > 0 && ($gmAverageRating !== null))
                        <div class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-low">
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl flex-shrink-0 mt-0.5" style="font-variation-settings: 'FILL' 1">star</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">
                                    {{ __('profile.dashboard_contributions_gm_rating', ['rating' => number_format($gmAverageRating, 1)]) }}
                                </p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- New Recaps Card --}}
        @if($newRecaps->count() > 0)
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
                    <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">auto_stories</span>
                    {{ __('attendance.dashboard_new_recaps') }}
                </h3>
                <ul class="space-y-2" role="list">
                    @foreach($newRecaps as $game)
                        <li>
                        <a href="{{ route('games.show', $game->id) }}" wire:navigate
                           class="flex items-center justify-between p-3 rounded-lg hover:bg-surface-container-low transition-colors group">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate">
                                    {{ $game->name }}
                                </p>
                                <p class="text-xs text-on-surface-variant">
                                    {{ __('attendance.dashboard_recap_by', ['name' => $game->owner?->name]) }}
                                </p>
                            </div>
                            <span aria-hidden="true" class="material-symbols-outlined text-primary text-lg ml-2" style="font-variation-settings: 'FILL' 1">arrow_forward</span>
                        </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Community Feed --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
                <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">monitoring</span>
                {{ __('profile.dashboard_feed_heading') }}
            </h3>

            @if($communityFeed->count() > 0)
                <ul class="space-y-1" role="list">
                    @foreach($communityFeed as $item)
                        @php
                            $routeName = $item->entityType === 'campaign' ? 'campaigns.show' : 'games.show';
                            $actionKey = match($item->type) {
                                'game_created' => 'profile.dashboard_feed_action_created_game',
                                'player_joined' => $item->entityType === 'campaign'
                                    ? 'profile.dashboard_feed_action_joined_campaign'
                                    : 'profile.dashboard_feed_action_joined_game',
                                'game_completed' => 'profile.dashboard_feed_action_completed_game',
                                'session_recapped' => 'profile.dashboard_feed_action_recapped_game',
                                'campaign_created' => 'profile.dashboard_feed_action_created_campaign',
                                'campaign_completed' => 'profile.dashboard_feed_action_completed_campaign',
                                'session_scheduled' => 'profile.dashboard_feed_action_scheduled_session',
                                default => 'profile.dashboard_feed_action_created_game',
                            };
                            $spotsLeft = ($item->maxPlayers && $item->participantCount !== null)
                                ? $item->maxPlayers - $item->participantCount
                                : null;
                        @endphp
                        <li>
                        <a href="{{ route($routeName, $item->entityId) }}" wire:navigate
                           class="flex items-start gap-3 py-3 {{ !$loop->last ? 'border-b border-outline-variant/30' : '' }} hover:bg-surface-container-low transition-colors rounded-lg px-2 -mx-2 group">
                            {{-- Avatar --}}
                            <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                                @if($item->userName)
                                    <span class="text-primary text-xs font-semibold">
                                        {{ Str::upper(Str::substr($item->userName, 0, 1)) }}
                                    </span>
                                @else
                                    <span class="material-symbols-outlined text-primary text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">local_fire_department</span>
                                @endif
                            </div>
                            {{-- Content --}}
                            <div class="min-w-0 flex-1">
                                <p class="text-on-surface text-sm leading-snug">
                                    @if($item->userName)
                                        <span class="font-semibold">{{ $item->userName }}</span>
                                    @endif
                                    {{ __($actionKey) }}
                                    <span class="font-semibold group-hover:text-primary transition-colors">{{ $item->entityName }}</span>
                                    @if($spotsLeft !== null && $spotsLeft > 0)
                                        <span class="text-on-surface-variant">— {{ trans_choice('profile.dashboard_feed_spots_left', $spotsLeft, ['count' => $spotsLeft]) }}</span>
                                    @elseif($item->maxPlayers && $item->participantCount !== null)
                                        <span class="text-on-surface-variant">— {{ __('profile.dashboard_feed_players', ['current' => $item->participantCount, 'max' => $item->maxPlayers]) }}</span>
                                    @endif
                                </p>
                                <time class="text-xs text-on-surface-variant mt-0.5 block"
                                      datetime="{{ $item->createdAt->toIso8601String() }}">
                                    {{ $item->createdAt->diffForHumans() }}
                                </time>
                            </div>
                        </a>
                        </li>
                    @endforeach
                </ul>

                {{-- Trending subsection --}}
                @if($hasTrendingSection && $trendingItems->count() > 0)
                    <div class="mt-6 pt-5 border-t border-outline-variant/30">
                        <h4 class="text-sm font-semibold text-on-surface-variant flex items-center gap-1.5 mb-3">
                            <span class="material-symbols-outlined text-primary text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">trending_up</span>
                            {{ __('profile.dashboard_feed_trending_heading') }}
                        </h4>
                        <ul class="space-y-1" role="list">
                            @foreach($trendingItems as $item)
                                @php
                                    $spotsLeft = ($item->maxPlayers && $item->participantCount !== null)
                                        ? $item->maxPlayers - $item->participantCount
                                        : null;
                                @endphp
                                <li>
                                <a href="{{ route('games.show', $item->entityId) }}" wire:navigate
                                   class="flex items-center gap-3 py-2.5 {{ !$loop->last ? 'border-b border-outline-variant/20' : '' }} hover:bg-surface-container-low transition-colors rounded-lg px-2 -mx-2 group">
                                    <div class="w-8 h-8 rounded-full bg-tertiary/10 flex items-center justify-center flex-shrink-0">
                                        <span class="material-symbols-outlined text-tertiary text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">local_fire_department</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-on-surface leading-snug">
                                            <span class="font-semibold group-hover:text-primary transition-colors">{{ $item->entityName }}</span>
                                            @if($spotsLeft !== null && $spotsLeft > 0)
                                                <span class="text-on-surface-variant">— {{ trans_choice('profile.dashboard_feed_spots_left', $spotsLeft, ['count' => $spotsLeft]) }}</span>
                                            @elseif($item->maxPlayers && $item->participantCount !== null)
                                                <span class="text-on-surface-variant">— {{ __('profile.dashboard_feed_players', ['current' => $item->participantCount, 'max' => $item->maxPlayers]) }}</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-on-surface-variant mt-0.5">
                                            <span class="inline-flex items-center gap-0.5 text-tertiary font-medium">
                                                <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' 1" aria-hidden="true">trending_up</span>
                                                {{ __('profile.dashboard_feed_trending_badge') }}
                                            </span>
                                            · <time datetime="{{ $item->createdAt->toIso8601String() }}">{{ $item->createdAt->diffForHumans() }}</time>
                                        </p>
                                    </div>
                                </a>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                {{-- Empty state --}}
                <div class="text-center py-12">
                    <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-5xl mb-3" style="font-variation-settings: 'FILL' 0">group</span>
                    <p class="text-on-surface-variant font-medium">{{ __('profile.dashboard_feed_empty_title') }}</p>
                    <p class="text-sm text-on-surface-variant mt-1">{{ __('profile.dashboard_feed_empty_desc') }}</p>
                    <a href="{{ route('people') }}" wire:navigate
                       class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                        <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">person_search</span>
                        {{ __('profile.dashboard_feed_find_people') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Quick Actions --}}
        @if(count($quickActions) > 0)
            <nav class="flex flex-wrap gap-3" aria-label="{{ __('profile.dashboard_quick_actions_heading') }}">
                @foreach($quickActions as $action)
                    <a href="{{ $action['url'] }}" wire:navigate
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $action['style'] === 'primary' ? 'bg-primary text-on-primary hover:bg-primary/90' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low' }}">
                        <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' {{ $action['style'] === 'primary' ? '1' : '0' }}">{{ $action['icon'] }}</span>
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif

    </div>
</div>
