{{-- Benched player banner (viewer-facing) --}}
@if($userBenchParticipant)
    <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">event_seat</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.content_you_are_on_the_bench') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_you_have_been_placed_on_the_bench') }}</p>
                <x-confirm-action
                    action="leaveBench('{{ $userBenchParticipant->id }}')"
                    id="leave-bench-{{ $userBenchParticipant->id }}"
                    :icon="'logout'"
                    :trigger-label="__('games.action_leave_bench')"
                    trigger-class="mt-3 inline-flex items-center gap-1 text-sm text-error hover:text-error/80 underline underline-offset-2 transition-colors"
                    :confirm-label="__('games.action_leave_bench')"
                    :cancel-label="__('common.action_keep')"
                    :message="__('games.flash_confirm_leave_bench')"
                    variant="compact"
                    severity="destructive"
                    confirm-icon="logout"
                />
            </div>
        </div>
    </section>
@endif
