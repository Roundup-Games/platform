@props([
    'communityFeed' => collect(),
])

@php
    $feedItems = $communityFeed->take(5);
    $hasItems = $feedItems->count() > 0;
@endphp

@if($hasItems)
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">monitoring</span>
        {{ __('profile.dashboard_pulse_heading') }}
    </h3>

    <ul class="space-y-1" role="list">
        @foreach($feedItems as $item)
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
            @endphp
            <li>
                <a href="{{ route($routeName, $item->entityId) }}" wire:navigate
                   class="flex items-center gap-3 py-2 {{ !$loop->last ? 'border-b border-outline-variant/20' : '' }} hover:bg-surface-container-low transition-colors rounded-lg px-2 -mx-2 group">
                    {{-- Avatar --}}
                    <div class="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                        @if($item->userName)
                            <span class="text-primary text-[10px] font-semibold">
                                {{ Str::upper(Str::substr($item->userName, 0, 1)) }}
                            </span>
                        @else
                            <span class="material-symbols-outlined text-primary text-xs" style="font-variation-settings: 'FILL' 1" aria-hidden="true">local_fire_department</span>
                        @endif
                    </div>
                    {{-- Content --}}
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-on-surface leading-snug truncate">
                            @if($item->userName)
                                <span class="font-semibold">{{ $item->userName }}</span>
                            @endif
                            {{ __($actionKey) }}
                            <span class="font-semibold group-hover:text-primary transition-colors">{{ $item->entityName }}</span>
                        </p>
                    </div>
                    @if($item->createdAt)
                        <time class="text-[10px] text-on-surface-variant shrink-0"
                              datetime="{{ $item->createdAt->toIso8601String() }}">
                            {{ $item->createdAt->diffForHumans(['short' => true]) }}
                        </time>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</div>
@endif
