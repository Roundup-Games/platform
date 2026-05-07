{{-- Waitlist position + confirmation banners (viewer-facing) --}}

{{-- Waitlist Position Banner --}}
@if($userWaitlistParticipant && $waitlistPosition)
    <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">playlist_add</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.action_join_waitlist') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_waitlist_position', ['position' => $waitlistPosition]) }}</p>
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
                    <button wire:click="declineWaitlistSpot('{{ $userPendingParticipant->id }}')"
                        wire:confirm="{{ __('people.flash_confirm_decline_invitation') }}"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant text-sm font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                        {{ __('games.action_decline_spot') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
@endif
