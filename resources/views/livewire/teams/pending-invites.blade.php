<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Team Invites</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your pending team invitations.</p>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)"
                 class="rounded-md bg-red-50 dark:bg-red-900/30 p-4">
                <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Invites List --}}
        @if($pendingInvites->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-8 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No pending invites</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You don't have any team invitations right now.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($pendingInvites as $invite)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('teams.detail', $invite->team->slug) }}" class="hover:text-[#C12E26] transition-colors">
                                        {{ $invite->team->name }}
                                    </a>
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Invited as <strong>{{ ucfirst($invite->role) }}</strong>
                                    @if($invite->invitedBy)
                                        by {{ $invite->invitedBy->name }}
                                    @endif
                                </p>
                                @if($invite->team->city)
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ $invite->team->city }}{{ $invite->team->country ? ', ' . $invite->team->country : '' }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="acceptInvite({{ $invite->id }})"
                                        class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                                    Accept
                                </button>
                                <button wire:click="declineInvite({{ $invite->id }})"
                                        class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors border border-gray-300 dark:border-gray-600 rounded-lg">
                                    Decline
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
