{{-- Benched player banner (viewer-facing) --}}
@if($userBenchParticipant)
    <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">event_seat</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.content_you_are_on_the_bench') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_you_have_been_placed_on_the_bench') }}</p>
                <button wire:click="leaveBench('{{ $userBenchParticipant->id }}')"
                    wire:confirm="{{ __('people.flash_confirm_decline_invitation') }}"
                    class="mt-3 inline-flex items-center gap-1 text-sm text-error hover:text-error/80 underline underline-offset-2 transition-colors">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">logout</span>
                    {{ __('games.action_leave_bench') }}
                </button>
            </div>
        </div>
    </section>
@endif
