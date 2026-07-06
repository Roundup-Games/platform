{{--
    Reusable game card for the My Games board.

    @var \App\Models\Game $game
    @var bool $asHost     Render host actions (edit/clone/complete/cancel) vs player (leave).
--}}
@php
    $isScheduled = $game->status->value === 'scheduled';
    $statusClass = $game->status->value === 'scheduled'
        ? 'bg-primary-container text-on-primary-container'
        : ($game->status->value === 'completed'
            ? 'bg-secondary-container text-on-secondary-container'
            : 'bg-error-container text-on-error-container');
@endphp

<div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
    {{-- Info area: clickable to detail --}}
    <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
       class="group block p-4 sm:p-5 hover:bg-surface-container/50 transition-colors">
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <h3 class="text-base font-medium text-on-surface group-hover:text-secondary transition-colors">
                {{ $game->name }}
            </h3>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                {{ __('games.status_' . $game->status->value) }}
            </span>
            @foreach($game->gameSystems as $system)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                    {{ $system->name }}
                </span>
            @endforeach
            @if($game->campaign)
                <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">campaign</span>
                    {{ $game->campaign->name }}
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
            @if(! $asHost && $game->owner)
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
    </a>

    {{-- Actions footer --}}
    @if($asHost)
        @if($isScheduled)
            <div class="border-t border-outline-variant/20 px-4 sm:px-5 py-2.5 flex flex-wrap gap-1">
                <button wire:click="editGame('{{ $game->id }}')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors"
                        aria-label="{{ __('games.action_edit_game') }}">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                    <span class="hidden sm:inline">{{ __('games.action_edit_game') }}</span>
                </button>
                <a href="{{ route('games.create') }}?clone={{ $game->id }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors"
                   aria-label="{{ __('games.action_create_similar_session') }}">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">content_copy</span>
                    <span class="hidden sm:inline">{{ __('games.action_create_similar_session') }}</span>
                </a>
                <button wire:click="completeGame('{{ $game->id }}')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-secondary hover:bg-secondary/10 transition-colors"
                        aria-label="{{ __('games.action_complete_game') }}">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                    <span class="hidden sm:inline">{{ __('games.action_complete_game') }}</span>
                </button>
                <x-confirm-action
                    action="cancelGame('{{ $game->id }}')"
                    id="cancel-game-{{ $game->id }}"
                    :icon="'cancel'"
                    :trigger-label="__('games.action_cancel_game')"
                    trigger-class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                    :confirm-label="__('games.action_cancel_game')"
                    :cancel-label="__('common.action_keep')"
                    :message="__('games.confirm_cancel_game')"
                    variant="inline"
                    severity="destructive"
                    confirm-icon="cancel"
                />
            </div>
        @else
            <div class="border-t border-outline-variant/20 px-4 sm:px-5 py-2.5 flex flex-wrap gap-1">
                <a href="{{ route('games.create') }}?clone={{ $game->id }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors"
                   aria-label="{{ __('games.action_create_similar_session') }}">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">content_copy</span>
                    <span class="hidden sm:inline">{{ __('games.action_create_similar_session') }}</span>
                </a>
            </div>
        @endif
    @elseif($isScheduled)
        {{-- Player view: leave button for scheduled games --}}
        <div class="border-t border-outline-variant/30 px-4 py-2 sm:px-5">
            <x-confirm-action
                action="leaveGame('{{ $game->id }}')"
                id="leave-game-{{ $game->id }}"
                :icon="'logout'"
                :trigger-label="__('games.action_leave_game')"
                trigger-class="inline-flex items-center gap-1.5 text-xs font-medium text-on-surface-variant hover:text-error transition-colors"
                :confirm-label="__('games.action_leave_game')"
                :cancel-label="__('common.action_keep')"
                :message="__('games.confirm_leave_game')"
                variant="inline"
                severity="destructive"
                confirm-icon="logout"
            />
        </div>
    @endif
</div>
