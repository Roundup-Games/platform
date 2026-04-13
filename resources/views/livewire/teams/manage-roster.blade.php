<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Manage Roster</h1>
            </div>
            <p class="ml-8 text-sm text-gray-500 dark:text-gray-400">Manage members for <strong>{{ $team->name }}</strong></p>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4" role="status" aria-live="polite">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)"
                 class="rounded-md bg-red-50 dark:bg-red-900/30 p-4" role="alert" aria-live="polite">
                <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
            </div>
        @endif>

        {{-- Invite Member --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Invite Member</h2>

            <div class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <input type="email" aria-label="Invite email address" wire:model="inviteEmail" placeholder="player@example.com"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('inviteEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <select aria-label="Invite role" wire:model="inviteRole"
                            class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                        <option value="player">Player</option>
                        <option value="substitute">Substitute</option>
                        <option value="coach">Coach</option>
                        <option value="captain">Captain</option>
                    </select>
                </div>
                <button wire:click="inviteMember" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium whitespace-nowrap">
                    <span wire:loading.remove>Send Invite</span>
                    <span wire:loading>Sending...</span>
                </button>
            </div>
        </section>

        {{-- Active Members --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">
                Active Members <span class="text-sm text-gray-500">({{ $activeMembers->count() }})</span>
            </h2>

            @if($activeMembers->isEmpty())
                <p class="text-gray-500 dark:text-gray-400 text-sm">No active members.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Name</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Role</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">#</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Position</th>
                                @if($isCaptain)
                                    <th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activeMembers as $member)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="py-2 px-3 text-gray-900 dark:text-gray-100">
                                        {{ $member->user->name }}
                                        @if($member->user_id === auth()->id())
                                            <span class="text-xs text-gray-400">(you)</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3">
                                        @if($editingMemberId === $member->id && $isCaptain)
                                            <select wire:model="editJerseyNumber" class="hidden"></select>
                                            {{-- Role dropdown for captain --}}
                                            <span class="inline-flex items-center gap-1">
                                                @php
                                                    $roleLabel = ucfirst($member->role);
                                                @endphp
                                                @if($isCaptain && $member->user_id !== auth()->id())
                                                    <select wire:change="setRole({{ $member->id }}, $event.target.value)"
                                                            class="text-xs rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 py-0.5 px-1">
                                                        @foreach(['captain', 'coach', 'player', 'substitute'] as $r)
                                                            <option value="{{ $r }}" {{ $member->role === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                        {{ $member->role === 'captain' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' : '' }}
                                                        {{ $member->role === 'coach' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' : '' }}
                                                        {{ $member->role === 'player' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : '' }}
                                                        {{ $member->role === 'substitute' ? 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300' : '' }}">
                                                        {{ $roleLabel }}
                                                    </span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                {{ $member->role === 'captain' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' : '' }}
                                                {{ $member->role === 'coach' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' : '' }}
                                                {{ $member->role === 'player' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : '' }}
                                                {{ $member->role === 'substitute' ? 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300' : '' }}">
                                                {{ ucfirst($member->role) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-gray-700 dark:text-gray-300">
                                        @if($editingMemberId === $member->id)
                                            <input type="text" wire:model="editJerseyNumber" maxlength="3"
                                                   class="w-14 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-sm py-0.5 px-1" />
                                        @else
                                            {{ $member->jersey_number ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-gray-700 dark:text-gray-300">
                                        @if($editingMemberId === $member->id)
                                            <input type="text" wire:model="editPosition" maxlength="50"
                                                   class="w-28 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-sm py-0.5 px-1" />
                                        @else
                                            {{ $member->position ?? '—' }}
                                        @endif
                                    </td>
                                    @if($isCaptain)
                                        <td class="py-2 px-3 text-right">
                                            @if($editingMemberId === $member->id)
                                                <button wire:click="saveMemberDetails"
                                                        class="text-xs px-2 py-1 bg-green-600 text-white rounded hover:bg-green-700">Save</button>
                                                <button wire:click="cancelEditing"
                                                        class="text-xs px-2 py-1 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 ml-1">Cancel</button>
                                            @else
                                                @if($member->user_id !== auth()->id())
                                                    <button wire:click="removeMember({{ $member->id }})"
                                                            onclick="confirm('Remove this member?') || event.preventDefault()"
                                                            class="text-xs px-2 py-1 text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">
                                                        Remove
                                                    </button>
                                                @endif
                                                <button wire:click="startEditing({{ $member->id }})"
                                                        class="text-xs px-2 py-1 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 ml-1">
                                                    Edit
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Pending Invites --}}
        @if($pendingInvites->isNotEmpty())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">
                    Pending Invites <span class="text-sm text-gray-500">({{ $pendingInvites->count() }})</span>
                </h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Name</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Email</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Role</th>
                                <th class="text-right py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingInvites as $invite)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ $invite->user->name }}</td>
                                    <td class="py-2 px-3 text-gray-500 dark:text-gray-400">{{ $invite->user->email }}</td>
                                    <td class="py-2 px-3 text-gray-700 dark:text-gray-300">{{ ucfirst($invite->role) }}</td>
                                    <td class="py-2 px-3 text-right">
                                        <button wire:click="cancelInvite({{ $invite->id }})"
                                                class="text-xs px-2 py-1 text-red-600 dark:text-red-400 hover:text-red-800">
                                            Cancel Invite
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- Leave Team --}}
        @if($activeMembers->contains('user_id', auth()->id()))
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 border-l-4 border-yellow-400">
                <h2 class="text-lg font-medium text-yellow-700 dark:text-yellow-400 mb-1 font-['Montserrat']">Leave Team</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">You will lose your roster spot. If you are the last captain, you must promote someone first.</p>
                <button onclick="confirm('Are you sure you want to leave this team?') || event.preventDefault()"
                        wire:click="leaveTeam"
                        class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors text-sm font-medium">
                    Leave Team
                </button>
            </section>
        @endif
    </div>
</div>
