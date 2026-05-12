@props(['activityFeed', 'entityType' => 'game'])

{{-- Community Activity Feed --}}
<section>
    <div class="flex items-center gap-3 mb-4">
        <div class="flex-1 h-px bg-outline-variant/30"></div>
        <h2 class="text-xl font-heading font-semibold text-on-surface">{{ $entityType === 'game' ? __('games.heading_community') : __('campaigns.heading_community') }}</h2>
        <div class="flex-1 h-px bg-outline-variant/30"></div>
    </div>

    @if($activityFeed->count())
        <div class="space-y-3">
            @foreach($activityFeed as $activity)
                @php
                    $entity = $activity->entity;
                    $user = $activity->user;
                    $route = $entityType === 'game'
                        ? route('games.detail', $entity->id)
                        : route('campaigns.detail', $entity->id);
                    $icon = match($activity->type) {
                        'game_created', 'campaign_created' => 'add_circle',
                        'player_joined' => 'person_add',
                        'game_completed', 'campaign_completed' => 'check_circle',
                        'session_scheduled' => 'event_add',
                        'session_recapped' => 'auto_stories',
                        default => 'notifications',
                    };
                @endphp

                <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-start gap-3">
                    {{-- Activity Icon --}}
                    <div class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center
                        {{ $activity->type === 'game_completed' || $activity->type === 'campaign_completed' ? 'bg-secondary-container' : ($activity->type === 'session_recapped' ? 'bg-tertiary/15' : 'bg-primary/10') }}">
                        <span class="material-symbols-outlined text-lg
                            {{ $activity->type === 'game_completed' || $activity->type === 'campaign_completed' ? 'text-on-secondary-container' : ($activity->type === 'session_recapped' ? 'text-tertiary' : 'text-primary') }}"
                            aria-hidden="true">{{ $icon }}</span>
                    </div>

                    {{-- Activity Content --}}
                    <div class="flex-1 min-w-0">
                        {{-- Actor line --}}
                        <div class="flex items-center gap-2 mb-1">
                            @if($user)
                                <a href="{{ route('profile.public', ['locale' => app()->getLocale(), 'user' => $user]) }}" wire:navigate
                                   class="text-sm font-medium text-secondary hover:text-secondary/80 transition-colors">
                                    {{ $user->name }}
                                </a>
                            @endif

                            @switch($activity->type)
                                @case('game_created')
                                    <span class="text-sm text-on-surface-variant">{{ __('games.activity_created_game') }}</span>
                                    @break
                                @case('campaign_created')
                                    <span class="text-sm text-on-surface-variant">{{ __('campaigns.activity_created_campaign') }}</span>
                                    @break
                                @case('player_joined')
                                    @if(isset($activity->users) && $activity->users->count() > 1)
                                        <span class="text-sm text-on-surface-variant">
                                            {{ __('common.activity_and_others', ['count' => $activity->users->count() - 1]) }}
                                            {{ $entityType === 'game' ? __('games.activity_joined_game') : __('campaigns.activity_joined_campaign') }}
                                        </span>
                                    @else
                                        <span class="text-sm text-on-surface-variant">{{ $entityType === 'game' ? __('games.activity_joined_game') : __('campaigns.activity_joined_campaign') }}</span>
                                    @endif
                                    @break
                                @case('game_completed')
                                    <span class="text-sm text-on-surface-variant">{{ __('games.activity_completed_game') }}</span>
                                    @break
                                @case('session_recapped')
                                    <span class="text-sm text-on-surface-variant">{{ __('games.activity_recapped_game') }}</span>
                                    @break
                                @case('campaign_completed')
                                    <span class="text-sm text-on-surface-variant">{{ __('campaigns.activity_completed_campaign') }}</span>
                                    @break
                                @case('session_scheduled')
                                    @if($activity->entity_campaign)
                                        <span class="text-sm text-on-surface-variant">
                                            {{ __('campaigns.activity_scheduled_session_for') }}
                                            <a href="{{ route('campaigns.detail', $activity->entity_campaign->id) }}" wire:navigate class="font-medium text-secondary hover:text-secondary/80 transition-colors">
                                                {{ $activity->entity_campaign->name }}
                                            </a>
                                        </span>
                                    @else
                                        <span class="text-sm text-on-surface-variant">{{ __('campaigns.activity_scheduled_session') }}</span>
                                    @endif
                                    @break
                            @endswitch
                        </div>

                        {{-- Entity name & link --}}
                        <a href="{{ $route }}" wire:navigate class="block group">
                            <h3 class="text-base font-medium text-on-surface group-hover:text-secondary transition-colors">
                                {{ $entity->name }}
                            </h3>
                        </a>

                        {{-- Meta line --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs text-on-surface-variant">
                            @if($entity->gameSystem)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">casino</span>
                                    {{ $entity->gameSystem->name }}
                                </span>
                            @endif

                            @if($entityType === 'game' && isset($entity->date_time))
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">calendar_today</span>
                                    {{ format_date($entity->date_time, 'datetime') }}
                                </span>
                            @endif

                            @if($entityType === 'campaign' && isset($entity->recurrence) && $entity->recurrence)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">repeat</span>
                                    {{ __('campaigns.content_' . $entity->recurrence) }}
                                </span>
                            @endif

                            @if(isset($entity->participants_count))
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                                    {{ trans_choice('common.content_joined', $entity->participants_count) }}
                                </span>
                            @endif

                            <span class="inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                {{ $activity->created_at->diffForHumans() }}
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($activityFeed->hasMorePages())
            <div class="mt-6">
                {{ $activityFeed->links() }}
            </div>
        @endif
    @else
        <div class="text-center py-16 bg-surface-container-low rounded-xl shadow-ambient">
            <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">group</span>
            <h3 class="mt-2 text-sm font-medium text-on-surface">{{ $entityType === 'game' ? __('games.content_no_community_activity') : __('campaigns.content_no_community_activity') }}</h3>
            <p class="mt-1 text-sm text-on-surface-variant">
                {{ $entityType === 'game' ? __('games.content_follow_players_to_see_activity') : __('campaigns.content_follow_players_to_see_activity') }}
            </p>
        </div>
    @endif
</section>
