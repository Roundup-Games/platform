@php
    $entity = ${$getEntityVar()};
@endphp

<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ $getBackRoute() }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('common.action_back_to_entity', ['entity' => $getEntityName()]) }}
            </a>
        </div>
    </div>

    {{-- Header --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-2xl" aria-hidden="true">group</span>
                {{ __('events.action_manage_participants') }}
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
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 overflow-visible">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">person_add</span>
                {{ __('teams.content_invite_player') }}
            </h2>

            <form wire:submit="inviteParticipants" class="space-y-4">
                <div>
                    <livewire:components.friend-search
                        :selected-ids="$selectedFriendIds"
                    />
                    @error('selectedFriendIds')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Divider --}}
                <div class="relative flex items-center my-4">
                    <div class="flex-grow border-t border-outline-variant/30"></div>
                    <span class="flex-shrink mx-3 text-xs text-on-surface-variant uppercase tracking-wider">{{ __('common.content_or') }}</span>
                    <div class="flex-grow border-t border-outline-variant/30"></div>
                </div>

                {{-- Email invite --}}
                <div>
                    <label for="invite-email" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('people.field_invite_by_email') }}
                    </label>
                    <div class="flex gap-2">
                        <input
                            type="email"
                            id="invite-email"
                            wire:model="inviteEmail"
                            class="flex-1 rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
                            placeholder="{{ __('people.placeholder_enter_email_address') }}"
                            autocomplete="off"
                        />
                        <button type="button"
                            wire:click="inviteByEmail"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">mail</span>
                            {{ __('people.action_send_invite') }}
                        </button>
                    </div>
                    @error('inviteEmail')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                    {{ __('teams.field_send_invite') }}
                </button>
            </form>
        </section>

        {{-- Approved Participants --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                {{ __('events.content_participants') }} <span class="text-on-surface-variant font-normal text-base">({{ $approvedParticipants->count() }})</span>
            </h2>

            @if($approvedParticipants->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($approvedParticipants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <x-user-link :user="$participant->user" avatar-size="w-10 h-10" :truncate="true" />
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'owner' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            @if($participant->join_source)
                                @php
                                    $joinSourceEnum = $participant->join_source;
                                    if ($joinSourceEnum instanceof \App\Enums\JoinSource) {
                                        $joinSourceBadge = $joinSourceEnum;
                                    } else {
                                        $joinSourceBadge = \App\Enums\JoinSource::tryFrom($joinSourceEnum);
                                    }
                                @endphp
                                @if($joinSourceBadge)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $joinSourceBadge === \App\Enums\JoinSource::ShareLink ? 'bg-tertiary/10 text-tertiary' : ($joinSourceBadge === \App\Enums\JoinSource::FriendInvite ? 'bg-primary/10 text-primary' : 'bg-secondary-container text-on-secondary-container') }}">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">
                                            {{ $joinSourceBadge === \App\Enums\JoinSource::ShareLink ? 'link' : ($joinSourceBadge === \App\Enums\JoinSource::FriendInvite ? 'person_add' : 'edit_note') }}
                                        </span>
                                        {{ $joinSourceBadge->label() }}
                                    </span>
                                @endif
                            @endif
                            @if($participant->role !== 'owner')
                                <button wire:click="removeParticipant('{{ $participant->id }}')"
                                    wire:confirm="{{ __('events.flash_are_you_sure_you_want_to_remove_this_participant') }}"
                                    class="text-sm text-error hover:text-error/80 transition-colors inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">person_remove</span>
                                    {{ __('common.action_remove') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('events.content_no_approved_participants_yet') }}</p>
            @endif
        </section>

        {{-- Pending Applications --}}
        @if($pendingApplicants->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">inbox</span>
                    {{ __('common.field_pending_applications') }} <span class="text-tertiary font-normal text-base">({{ $pendingApplicants->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($pendingApplicants as $applicant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="flex-1 min-w-0">
                                <x-user-link :user="$applicant->user" avatar-size="w-10 h-10" :truncate="true" />
                                @php
                                    $app = $entity->applications->firstWhere('user_id', $applicant->user_id)
                                @endphp
                                @if($app?->message)
                                    <p class="text-xs text-on-surface-variant truncate ml-12">{{ $app->message }}</p>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="approveApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-secondary text-on-secondary text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                                    {{ __('events.action_approve') }}
                                </button>
                                <button wire:click="rejectApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-error-container text-on-error-container text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                                    {{ __('common.action_reject') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Pending Invites --}}
        @if($pendingInvites->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">mail</span>
                    {{ __('teams.field_pending_invites') }} <span class="text-on-surface-variant font-normal text-base">({{ $pendingInvites->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($pendingInvites as $invite)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="flex-1 min-w-0">
                                @if($invite->user)
                                    <x-user-link :user="$invite->user" avatar-size="w-10 h-10" :truncate="true" />
                                    <p class="text-xs text-on-surface-variant ml-12">{{ $invite->user->email }}</p>
                                @else
                                    {{-- Email invitee without account --}}
                                    <div class="flex items-center gap-2">
                                        <div class="w-10 h-10 rounded-full bg-surface-container-high flex items-center justify-center">
                                            <span class="material-symbols-outlined text-on-surface-variant text-lg" aria-hidden="true">mail</span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-on-surface">{{ $invite->invitee_email }}</div>
                                            <span class="inline-flex items-center gap-1 text-xs text-on-surface-variant">
                                                <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                                {{ __('people.content_pending_account_creation') }}
                                            </span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <button wire:click="cancelInvite('{{ $invite->id }}')"
                                wire:confirm="{{ __('common.flash_cancel_this_invite') }}"
                                class="text-sm text-on-surface-variant hover:text-error transition-colors inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">cancel</span>
                                {{ __('common.action_cancel') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Waitlisted Players --}}
        @if($waitlistedParticipants->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">schedule</span>
                    {{ __('games.content_waitlist_management') }} <span class="text-tertiary font-normal text-base">({{ $waitlistedParticipants->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($waitlistedParticipants as $loopIndex => $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-tertiary/10 text-tertiary text-xs font-bold flex-shrink-0">
                                #{{ $loopIndex + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <x-user-link :user="$participant->user" :show-avatar="false" :truncate="true" />
                                @if($participant->waitlisted_at)
                                    <p class="text-xs text-on-surface-variant mt-0.5">{{ $participant->waitlisted_at->format('M j, Y') }}</p>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="managePromoteFromWaitlist('{{ $participant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-secondary text-on-secondary text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                    {{ __('games.action_promote_from_bench') }}
                                </button>
                                <button wire:click="manageRemoveFromWaitlist('{{ $participant->id }}')"
                                    wire:confirm="{{ __('events.flash_are_you_sure_you_want_to_remove_this_participant') }}"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-error-container text-on-error-container text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">person_remove</span>
                                    {{ __('common.action_remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Benched Players --}}
        @if($benchedParticipants->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">event_seat</span>
                    {{ __('games.content_bench') }} <span class="text-on-surface-variant font-normal text-base">({{ $benchedParticipants->count() }})</span>
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($benchedParticipants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="flex-1 min-w-0">
                                <x-user-link :user="$participant->user" :show-avatar="false" :truncate="true" />
                                @if($participant->benched_at)
                                    <p class="text-xs text-on-surface-variant mt-0.5">{{ $participant->benched_at->format('M j, Y') }}</p>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="managePromoteFromBench('{{ $participant->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-secondary text-on-secondary text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                    {{ __('games.action_promote_from_bench') }}
                                </button>
                                <button wire:click="manageRemoveFromBench('{{ $participant->id }}')"
                                    wire:confirm="{{ __('events.flash_are_you_sure_you_want_to_remove_this_participant') }}"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-error-container text-on-error-container text-xs font-medium rounded-lg hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">person_remove</span>
                                    {{ __('common.action_remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
