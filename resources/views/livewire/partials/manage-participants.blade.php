@php
    $entity = ${$getEntityVar()};
@endphp

<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ $getBackRoute() }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to {{ $getEntityName() }}
        </a>

        {{-- Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h1 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">
                Manage Participants
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $entity->name }}</p>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show"
                 class="rounded-md bg-green-50 dark:bg-green-900/20 p-4" role="status" aria-live="polite">
                <p class="text-sm text-green-700 dark:text-green-400">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="rounded-md bg-red-50 dark:bg-red-900/20 p-4" role="alert" aria-live="polite">
                <p class="text-sm text-red-700 dark:text-red-400">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Invite Form --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Invite Player</h2>

            <form wire:submit="inviteParticipant" class="flex gap-3">
                <div class="flex-1">
                    <input type="email" wire:model="inviteEmail" placeholder="player@example.com"
                        class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26] text-sm"
                        data-testid="invite-email" />
                    @error('inviteEmail')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-[#C12E26] hover:bg-[#9A231F] text-white text-sm font-medium rounded-md transition-colors">
                    Send Invite
                </button>
            </form>
        </section>

        {{-- Approved Participants --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">
                Participants <span class="text-gray-400 font-normal text-base">({{ $approvedParticipants->count() }})</span>
            </h2>

            @if($approvedParticipants->count())
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($approvedParticipants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase
                                {{ $participant->role === 'owner' ? 'bg-[#C12E26]/10 text-[#C12E26]' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' }}">
                                {{ strtoupper($participant->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $participant->user->name }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'owner' ? 'bg-[#C12E26]/10 text-[#C12E26]' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            @if($participant->role !== 'owner')
                                <button wire:click="removeParticipant('{{ $participant->id }}')"
                                    wire:confirm="Are you sure you want to remove this participant?"
                                    class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 transition-colors">
                                    Remove
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 italic py-4 text-center">No approved participants yet.</p>
            @endif
        </section>

        {{-- Pending Applications --}}
        @if($pendingApplicants->count())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">
                    Pending Applications <span class="text-yellow-500 font-normal text-base">({{ $pendingApplicants->count() }})</span>
                </h2>

                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($pendingApplicants as $applicant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400">
                                {{ strtoupper($applicant->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $applicant->user->name }}</p>
                                @php
                                    $app = $entity->applications->firstWhere('user_id', $applicant->user_id)
                                @endphp
                                @if($app?->message)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $app->message }}</p>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                <button wire:click="approveApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-md transition-colors">
                                    Approve
                                </button>
                                <button wire:click="rejectApplication('{{ $applicant->id }}')"
                                    class="inline-flex items-center px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-md transition-colors">
                                    Reject
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Pending Invites --}}
        @if($pendingInvites->count())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">
                    Pending Invites <span class="text-blue-500 font-normal text-base">({{ $pendingInvites->count() }})</span>
                </h2>

                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($pendingInvites as $invite)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                                {{ strtoupper($invite->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $invite->user->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $invite->user->email }}</p>
                            </div>
                            <button wire:click="cancelInvite('{{ $invite->id }}')"
                                wire:confirm="Cancel this invite?"
                                class="text-sm text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                Cancel
                            </button>
                        </div>
                    @endforeach>
                </div>
            </section>
        @endif
    </div>
</div>
