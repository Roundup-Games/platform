@props(['game' => []])

@php
    $isHost = $game['is_hosting'] ?? false;
    $playerCount = $game['player_count'] ?? 0;
    $maxPlayers = $game['max_players'] ?? null;
    $systemBadge = $game['system_badge'] ?? [];
    $campaignName = $game['campaign_name'] ?? null;
    $dateTime = isset($game['date_time']) ? \Carbon\Carbon::parse($game['date_time']) : null;
@endphp

<a href="{{ route('games.show', $game['id']) }}" wire:navigate
   class="flex items-center justify-between p-3 rounded-lg bg-surface-container-low hover:bg-primary/5 transition-colors group">
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 flex-wrap">
            {{-- Game name --}}
            <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate">
                {{ $game['name'] }}
            </p>

            {{-- System badge --}}
            @if($systemBadge['name'] ?? null)
                <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary flex-shrink-0">
                    {{ $systemBadge['name'] }}
                </span>
            @endif

            {{-- Host badge --}}
            @if($isHost)
                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary flex-shrink-0">
                    ★ {{ __('attendance.dashboard_hosting') }}
                </span>
            @endif
        </div>

        <div class="flex items-center gap-3 mt-1 flex-wrap">
            {{-- Relative time --}}
            @if($game['relative_time'] ?? null)
                <span class="text-xs text-on-surface-variant">
                    {{ $game['relative_time'] }}
                </span>
            @endif

            {{-- Campaign name --}}
            @if($campaignName)
                <span class="text-xs text-primary/70 flex items-center gap-0.5">
                    <span class="material-symbols-outlined text-[10px]" aria-hidden="true">campaign</span>
                    {{ $campaignName }}
                </span>
            @endif

            {{-- Player count --}}
            @if($maxPlayers)
                <span class="text-xs text-on-surface-variant flex items-center gap-0.5">
                    <span class="material-symbols-outlined text-[10px]" aria-hidden="true">group</span>
                    {{ $playerCount }}/{{ $maxPlayers }}
                </span>
            @endif
        </div>
    </div>

    <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-lg ml-2 flex-shrink-0 group-hover:text-primary transition-colors"
          style="font-variation-settings: 'FILL' 0">chevron_right</span>
</a>
