{{-- Waitlist position + confirmation banners (viewer-facing) --}}

{{-- Waitlist Position Banner --}}
@if($userWaitlistParticipant && $waitlistPosition)
    <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">playlist_add</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.action_join_waitlist') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_waitlist_position', ['position' => $waitlistPosition]) }}</p>
                <x-confirm-action
                    action="leaveWaitlist('{{ $userWaitlistParticipant->id }}')"
                    id="leave-waitlist-{{ $userWaitlistParticipant->id }}"
                    :icon="'logout'"
                    :trigger-label="__('games.action_leave_waitlist')"
                    trigger-class="mt-3 inline-flex items-center gap-1 text-sm text-error hover:text-error/80 underline underline-offset-2 transition-colors"
                    :confirm-label="__('games.action_leave_waitlist')"
                    :cancel-label="__('common.action_keep')"
                    :message="__('games.flash_confirm_leave_waitlist')"
                    variant="compact"
                    severity="destructive"
                    confirm-icon="logout"
                />
            </div>
        </div>
    </section>
@endif

{{-- Waitlist Confirmation Banner --}}
@if($userPendingParticipant && $userPendingParticipant->confirmation_expires_at)
    <section class="bg-secondary-container/50 border border-secondary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-on-secondary-container mt-0.5" aria-hidden="true">event_available</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.action_confirm_spot') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">
                    {{ __('games.content_spot_opened_confirm', ['deadline' => $userPendingParticipant->confirmation_expires_at->isoFormat('LLL')]) }}
                </p>
                <div class="mt-4 flex gap-3">
                    <button wire:click="confirmWaitlistSpot('{{ $userPendingParticipant->id }}')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                        {{ __('games.action_confirm_spot') }}
                    </button>
                    <x-confirm-action
                        action="declineWaitlistSpot('{{ $userPendingParticipant->id }}')"
                        id="decline-waitlist-spot-{{ $userPendingParticipant->id }}"
                        :icon="'close'"
                        :trigger-label="__('games.action_decline_spot')"
                        trigger-class="inline-flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant text-sm font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors"
                        :confirm-label="__('games.action_decline_spot')"
                        :cancel-label="__('common.action_keep')"
                        :message="__('people.flash_confirm_decline_invitation')"
                        variant="compact"
                        severity="destructive"
                        confirm-icon="close"
                    />
                </div>
            </div>
        </div>
    </section>
@endif
