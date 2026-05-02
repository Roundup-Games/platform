<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.action_manage_roster') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{!! __('teams.field_manage_members_for_name', ['name' => '<strong>' . e($team->name) . '</strong>']) !!}</p>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ session('success') }}
                </p>
            </div>
        @endif

        @if(session()->has('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)"
                 class="rounded-lg bg-error-container p-4" role="alert" aria-live="polite">
                <p class="text-sm text-on-error-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">error</span>
                    {{ session('error') }}
                </p>
            </div>
        @endif

        {{-- Invite Member --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface tracking-tight mb-4">{{ __('teams.content_invite_member') }}</h2>

            <div class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <input type="email" aria-label="Invite email address" wire:model="inviteEmail" placeholder="player@example.com"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('inviteEmail') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <select aria-label="Invite role" wire:model="inviteRole"
                            class="rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                        <option value="player">{{ __('common.content_player') }}</option>
                        <option value="substitute">{{ __('common.content_substitute') }}</option>
                        <option value="coach">{{ __('common.content_coach') }}</option>
                        <option value="captain">{{ __('teams.content_captain') }}</option>
                    </select>
                </div>
                <button wire:click="inviteMember" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium whitespace-nowrap">
                    <span wire:loading.remove>{{ __('teams.field_send_invite') }}</span>
                    <span wire:loading>{{ __('common.content_sending') }}</span>
                </button>
            </div>
        </section>

        {{-- Active Members --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface tracking-tight mb-4">
                {{ __('teams.content_active_members') }} <span class="text-sm font-normal text-on-surface-variant">({{ $activeMembers->count() }})</span>
            </h2>

            @if($activeMembers->isEmpty())
                <p class="text-on-surface-variant text-sm">{{ __('teams.error_no_active_members') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-outline-variant">
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('common.field_name') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('teams.content_roster') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">#</th>
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('common.content_position', ['default' => 'Position']) }}</th>
                                @if($isCaptain)
                                    <th class="text-right py-2 px-3 font-medium text-on-surface-variant">{{ __('profile.content_actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activeMembers as $member)
                                <tr class="border-b border-outline-variant/30 hover:bg-surface-container-low transition-colors">
                                    <td class="py-2 px-3 text-on-surface">
                                        <x-user-link :user="$member->user" :show-avatar="false" />
                                        @if($member->user_id === auth()->id())
                                            <span class="text-xs text-on-surface-variant">{{ __('common.content_you') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3">
                                        @if($editingMemberId === $member->id && $isCaptain)
                                            <select wire:change="setRole('{{ $member->id }}', $event.target.value)"
                                                    class="text-xs rounded bg-surface-container-high border border-transparent py-0.5 px-1 text-on-surface">
                                                @foreach(['captain', 'coach', 'player', 'substitute'] as $r)
                                                    <option value="{{ $r }}" {{ $member->role === $r ? 'selected' : '' }}>{{ __(ucfirst($r)) }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                {{ $member->role === 'captain' ? 'bg-tertiary-container text-on-tertiary-container' : '' }}
                                                {{ $member->role === 'coach' ? 'bg-primary-container text-on-primary-container' : '' }}
                                                {{ $member->role === 'player' ? 'bg-secondary-container text-on-secondary-container' : '' }}
                                                {{ $member->role === 'substitute' ? 'bg-surface-container-high text-on-surface-variant' : '' }}">
                                                {{ __(ucfirst($member->role)) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-on-surface">
                                        @if($editingMemberId === $member->id)
                                            <input type="text" wire:model="editJerseyNumber" maxlength="3"
                                                   class="w-14 rounded bg-surface-container-high border border-transparent text-sm py-0.5 px-1 text-on-surface" />
                                        @else
                                            {{ $member->jersey_number ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-on-surface">
                                        @if($editingMemberId === $member->id)
                                            <input type="text" wire:model="editPosition" maxlength="50"
                                                   class="w-28 rounded bg-surface-container-high border border-transparent text-sm py-0.5 px-1 text-on-surface" />
                                        @else
                                            {{ $member->position ?? '—' }}
                                        @endif
                                    </td>
                                    @if($isCaptain)
                                        <td class="py-2 px-3 text-right">
                                            @if($editingMemberId === $member->id)
                                                <button wire:click="saveMemberDetails"
                                                        class="text-xs px-2 py-1 bg-secondary-container text-on-secondary-container rounded hover:brightness-110 transition-all">{{ __('common.action_save_changes') }}</button>
                                                <button wire:click="cancelEditing"
                                                        class="text-xs px-2 py-1 text-on-surface-variant hover:text-on-surface ml-1">{{ __('common.action_cancel') }}</button>
                                            @else
                                                @if($member->user_id !== auth()->id())
                                                    <button wire:click="removeMember('{{ $member->id }}')"
                                                            onclick="confirm('{{ __('teams.flash_remove_this_member') }}') || event.preventDefault()"
                                                            class="text-xs px-2 py-1 text-error hover:brightness-110">
                                                        {{ __('common.action_remove') }}
                                                    </button>
                                                @endif
                                                <button wire:click="startEditing('{{ $member->id }}')"
                                                        class="text-xs px-2 py-1 text-on-surface-variant hover:text-on-surface ml-1">
                                                    {{ __('common.action_edit') }}
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
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-heading font-semibold text-on-surface tracking-tight mb-4">
                    {{ __('teams.field_pending_invites') }} <span class="text-sm font-normal text-on-surface-variant">({{ $pendingInvites->count() }})</span>
                </h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-outline-variant">
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('common.field_name') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('emails.field_email') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-on-surface-variant">{{ __('teams.content_roster') }}</th>
                                <th class="text-right py-2 px-3 font-medium text-on-surface-variant">{{ __('profile.content_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingInvites as $invite)
                                <tr class="border-b border-outline-variant/30">
                                    <td class="py-2 px-3 text-on-surface">
                                        <x-user-link :user="$invite->user" :show-avatar="false" />
                                    </td>
                                    <td class="py-2 px-3 text-on-surface-variant">{{ $invite->user?->email ?? '' }}</td>
                                    <td class="py-2 px-3 text-on-surface">{{ __(ucfirst($invite->role)) }}</td>
                                    <td class="py-2 px-3 text-right">
                                        <button wire:click="cancelInvite('{{ $invite->id }}')"
                                                class="text-xs px-2 py-1 text-error hover:brightness-110">
                                            {{ __('common.action_cancel_invite') }}
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
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-tertiary">
                <h2 class="text-lg font-heading font-semibold text-tertiary mb-1 tracking-tight">{{ __('teams.action_leave_team') }}</h2>
                <p class="text-sm text-on-surface-variant mb-4">{{ __('teams.content_you_will_lose_your_roster') }}</p>
                <button onclick="confirm('{{ __('teams.flash_are_you_sure_you_want_to_leave_this_team') }}') || event.preventDefault()"
                        wire:click="leaveTeam"
                        class="px-4 py-2 bg-tertiary text-on-tertiary rounded-lg hover:brightness-110 transition-all text-sm font-medium">
                    {{ __('teams.action_leave_team') }}
                </button>
            </section>
        @endif
    </div>
</div>
