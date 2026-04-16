@php
    $entity = ${$getEntityVar()};
@endphp

<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ $getBackRoute() }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('Back to :entity', ['entity' => $getEntityName()]) }}
            </a>
        </div>
    </div>

    {{-- Header --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-2xl" aria-hidden="true">group_manage</span>
                {{ __('Manage Participants') }}
            </h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ $entity->name }}</p>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show"
                 class="rounded-xl bg-secondary-container p-4 flex items-center gap-3" role="status" aria-live="polite">
                <span class="material-symbols-outlined text-on-secondary-container" aria-hidden="true">check_circle</span>
                <p class="text-sm text-on-secondary-container">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="rounded-xl bg-error-container p-4 flex items-center gap-3" role="alert" aria-live="polite">
                <span class="material-symbols-outlined text-on-error-container" aria-hidden="true">error</span>
                <p class="text-sm text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Invite Form --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">person_add</span>
                {{ __('Invite Player') }}
            </h2>

            <form wire:submit="inviteParticipant" class="flex gap-3">
                <div class="flex-1">
                    <input type="email" wire:model="inviteEmail" placeholder="player@example.com"
                        class="block w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-sm transition-colors"
                        data-testid="invite-email" />
                    @error('inviteEmail')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                    {{ __('Send Invite') }}
                </button>
            </form>
        </section>

        {{-- Approved Participants --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                {{ __('Participants') }} <span class="text-on-surface-variant font-normal text-base">({{ $approvedParticipants->count() }})</span>
            </h2>

            @if($approvedParticipants->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($approvedParticipants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                                {{ $participant->role === 'owner' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->user?->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $participant->user?->name ?? __('Unknown') }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'owner' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            @if($participant->role !== 'owner')
                                <button wire:click="removeParticipant('{{ $participant->id }}')"
                                    wire:confirm="{{ __('Are you sure you want to remove this participant?') }}"
                                    class="text-sm text-error hover:text-error/80 transition-colors inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">person_remove</span>
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('No approved participants yet.') }}</p>
            @endif
        </section>

        {{-- Pending Applications --}}
        @if($pendingApplicants->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">inbox</span>
                    {{ __('Pending Applications') }} <span class="text-tertiary font-normal text-base">({{ $pendingApplicants->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($pendingApplicants as $applicant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold bg-tertiary/10 text-tertiary">
                                {{ strtoupper($applicant->user?->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $applicant->user?->name ?? __('Unknown') }}</p>
                                @php
                                    $app = $entity->applications->firstWhere('user_id', $applicant->user_id)
                                @endphp
                                @if($app?->message)
                                    <p class="text-xs text-on-surface-variant truncate">{{ $app->message }}</p>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="approveApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-secondary text-on-secondary text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                                    {{ __('Approve') }}
                                </button>
                                <button wire:click="rejectApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-error-container text-on-error-container text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                                    {{ __('Reject') }}
                                </button>
                            </div>
                        </div>
                    @endforeach>
                </div>
            </section>
        @endif

        {{-- Pending Invites --}}
        @if($pendingInvites->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">mail</span>
                    {{ __('Pending Invites') }} <span class="text-on-surface-variant font-normal text-base">({{ $pendingInvites->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($pendingInvites as $invite)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold bg-primary/10 text-primary">
                                {{ strtoupper($invite->user?->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $invite->user?->name ?? __('Unknown') }}</p>
                                <p class="text-xs text-on-surface-variant">{{ $invite->user?->email ?? '' }}</p>
                            </div>
                            <button wire:click="cancelInvite('{{ $invite->id }}')"
                                wire:confirm="{{ __('Cancel this invite?') }}"
                                class="text-sm text-on-surface-variant hover:text-error transition-colors inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">cancel</span>
                                {{ __('Cancel') }}
                            </button>
                        </div>
                    @endforeach>
                </div>
            </section>
        @endif
    </div>
</div>
