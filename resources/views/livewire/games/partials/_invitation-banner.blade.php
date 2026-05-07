{{-- Invitation accept/decline banner for invited users --}}
@if($userInvitation)
    <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
        <div class="flex items-start gap-4">
            <span class="material-symbols-outlined text-2xl text-primary mt-0.5" aria-hidden="true">mail</span>
            <div class="flex-1">
                <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('common.action_accept_invitation') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('people.content_you_have_been_invited') }}</p>
                <div class="mt-4 flex gap-3">
                    <button wire:click="acceptInvitation('{{ $userInvitation->id }}')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                        {{ __('common.action_accept') }}
                    </button>
                    <button wire:click="declineInvitation('{{ $userInvitation->id }}')"
                        wire:confirm="{{ __('people.flash_confirm_decline_invitation') }}"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant text-sm font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                        {{ __('common.action_decline') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
@endif
