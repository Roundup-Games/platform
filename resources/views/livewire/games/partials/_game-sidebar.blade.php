{{-- Game detail sidebar: join CTA, host, applications, waitlist/bench management --}}
<aside class="space-y-6">

    {{-- Spot Availability --}}
    @auth
        @php
            $approvedCount = $game->participants->where('status.value', 'approved')->count();
            $spotsLeft = $game->max_players ? max(0, $game->max_players - $approvedCount) : null;
            $waitlistedCount = $game->participants->where('status.value', 'waitlisted')->count();
            $benchedCount = $game->participants->where('status.value', 'benched')->count();
        @endphp
        @if($spotsLeft !== null && !$isOwner)
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-on-surface">{{ __('games.content_availability') }}</span>
                    @if($spotsLeft === 0)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-error/10 text-error">
                            {{ __('games.content_full') }}
                        </span>
                    @elseif($spotsLeft <= 2)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/15 text-primary">
                            {{ trans_choice('games.content_spots_left_short', $spotsLeft) }}
                        </span>
                    @else
                        <span class="text-sm text-on-surface-variant">
                            {{ $approvedCount }}/{{ $game->max_players }}
                        </span>
                    @endif
                </div>
                @if($waitlistedCount > 0 || $benchedCount > 0)
                    <div class="mt-2 flex items-center gap-3 text-xs text-on-surface-variant">
                        @if($waitlistedCount > 0)
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                {{ $waitlistedCount }} {{ trans_choice('games.content_waitlisted_count', $waitlistedCount) }}
                            </span>
                        @endif
                        @if($benchedCount > 0)
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">event_seat</span>
                                {{ $benchedCount }} {{ trans_choice('games.content_benched_count', $benchedCount) }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @endauth

    {{-- Join via Share Link CTA --}}
    @auth
        @if($canJoinViaShareLink)
            <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">link</span>
                    {{ __('games.action_join_via_share_link') }}
                </h3>
                @if($isGameFull)
                    <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_game_full_join_waitlist') }}</p>
                @endif
                <button wire:click="joinViaShareLink"
                    class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">login</span>
                    @if($isGameFull)
                        {{ __('games.action_join_waitlist') }}
                    @else
                        {{ __('games.action_join_game') }}
                    @endif
                </button>
            </div>
        @endif
    @endauth

    {{-- Join / Apply CTA --}}
    @auth
        @if($canApply && !$canJoinViaShareLink)
            <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">
                        @if($game->visibility->value === 'public') login @else edit_note @endif
                    </span>
                    @if($game->visibility->value === 'public')
                        {{ __('games.action_join_game') }}
                    @else
                        {{ __('games.action_apply_to_join') }}
                    @endif
                </h3>
                @if($game->visibility->value === 'protected')
                    <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_this_is_a_protected_game') }}</p>
                @endif
                <a href="{{ route('games.apply', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">
                        @if($game->visibility->value === 'public') login @else send @endif
                    </span>
                    @if($game->visibility->value === 'public')
                        {{ __('games.action_join_game') }}
                    @else
                        {{ __('games.action_apply_to_join') }}
                    @endif
                </a>
            </div>
        @elseif($hasExistingApplication)
            <div class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6 text-center">
                <span class="material-symbols-outlined text-3xl text-tertiary mb-2" aria-hidden="true">schedule</span>
                <p class="text-on-surface font-medium">{{ __('games.content_application_pending') }}</p>
                <p class="text-sm text-on-surface-variant mt-1">{{ __('games.content_waiting_for_host_approval') }}</p>
            </div>
        @elseif($canJoinWaitlist)
            <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">playlist_add</span>
                    {{ __('games.action_join_waitlist') }}
                </h3>
                <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_game_full_join_waitlist') }}</p>
                <button wire:click="joinWaitlist"
                    class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                    {{ __('games.action_join_waitlist') }}
                </button>
            </div>
        @endif
    @else
        <x-registration-cta :message="__('games.guest_nudge_join_game')" />
    @endauth

    {{-- Host --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-lg" aria-hidden="true">person</span>
            {{ __('common.content_hosted_by') }}
        </h3>
        <div class="flex items-center gap-3">
            <x-user-link :user="$game->owner" avatar-size="w-11 h-11" />
            @if($game->owner->isGM())
                <x-gm-badge size="sm" />
            @endif
        </div>
    </div>

    {{-- Manage Participants (owner only) --}}
    @if($isOwner)
        <a href="{{ route('games.manage-participants', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
           class="block bg-surface-container-low rounded-xl shadow-ambient p-4 hover:bg-surface-container-high transition-colors group">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-xl text-on-surface-variant group-hover:text-primary transition-colors" aria-hidden="true">group</span>
                <div class="flex-1 min-w-0">
                    <span class="text-sm font-medium text-on-surface">{{ __('events.action_manage_participants') }}</span>
                    <p class="text-xs text-on-surface-variant">{{ trans_choice('common.content_count_participants', $game->participants->count()) }}</p>
                </div>
                <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">chevron_right</span>
            </div>
        </a>
    @endif

    {{-- Applications (owner only) --}}
    @if($isOwner && $game->applications->count())
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">inbox</span>
                {{ __('common.content_applications') }}
            </h3>
            <div class="divide-y divide-outline-variant/30">
                @foreach($game->applications as $application)
                    <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="flex-1 min-w-0">
                            <x-user-link :user="$application->user" avatar-size="w-9 h-9" :truncate="true" />
                            @if($application->message)
                                <p class="text-xs text-on-surface-variant truncate ml-11">{{ $application->message }}</p>
                            @endif
                        </div>
                        @if($application->status === 'pending')
                            @php
                                $participant = $game->participants->firstWhere('user_id', $application->user_id);
                            @endphp
                            @if($participant)
                                <div class="flex items-center gap-1 shrink-0">
                                    <button wire:click="approveApplication('{{ $participant->id }}')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-secondary-container text-on-secondary-container hover:bg-secondary/20 transition-colors">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                                        {{ __('common.action_approve') }}
                                    </button>
                                    <button wire:click="rejectApplication('{{ $participant->id }}')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-error-container text-on-error-container hover:bg-error/20 transition-colors">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                                        {{ __('common.action_reject') }}
                                    </button>
                                </div>
                            @endif
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium shrink-0
                                {{ $application->status === 'approved' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container' }}">
                                {{ __('games.status_' . $application->status) }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Waitlist Management (owner only, standalone games) --}}
    @if($isOwner && $waitlistedPlayers->count())
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">playlist_add</span>
                {{ __('games.content_waitlist_management') }}
            </h3>
            <div class="divide-y divide-outline-variant/30">
                @foreach($waitlistedPlayers as $waitlisted)
                    <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="flex-1 min-w-0">
                            <x-user-link :user="$waitlisted->user" avatar-size="w-9 h-9" :truncate="true" />
                            <p class="text-xs text-on-surface-variant ml-11">
                                {{ __('games.content_waitlist_position', ['position' => $loop->iteration]) }}
                            </p>
                        </div>
                        <button wire:click="manualPromote('{{ $waitlisted->id }}')"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                            {{ __('games.action_manual_promote') }}
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Bench Management (owner only, campaign sessions) --}}
    @if($isOwner && $benchedPlayers->count())
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">event_seat</span>
                {{ __('games.content_bench') }}
            </h3>
            <p class="text-xs text-on-surface-variant mb-3">{{ __('games.content_bench_description') }}</p>
            <div class="divide-y divide-outline-variant/30">
                @foreach($benchedPlayers as $benched)
                    <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="flex-1 min-w-0">
                            <x-user-link :user="$benched->user" avatar-size="w-9 h-9" :truncate="true" />
                        </div>
                        <button wire:click="promoteFromBench('{{ $benched->id }}')"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                            {{ __('games.action_promote_from_bench') }}
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Report (authenticated non-owners only) --}}
    @auth
        @unless($isOwner)
            <div class="flex justify-end">
                <livewire:reports.report-content :entityType="'game'" :entityId="$game->id" :key="'report-game-' . $game->id" />
            </div>
        @endunless
    @endauth
</aside>
