<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.content_team_invites') }}</h1>
            <p class="text-sm text-on-surface-variant mt-1">{{ __('teams.action_manage_your_pending_team_invitations') }}</p>
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

        {{-- Invites List --}}
        @if($pendingInvites->isEmpty())
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8 text-center">
                <span class="material-symbols-outlined text-4xl text-on-surface-variant/50" aria-hidden="true">mail</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('teams.error_no_pending_invites') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('teams.error_you_don_t_have_any_team_invitations_right_now') }}</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($pendingInvites as $invite)
                    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-on-surface">
                                    <a href="{{ route('teams.detail', $invite->team?->slug) }}" wire:navigate class="hover:text-primary transition-colors">
                                        {{ $invite->team?->name }}
                                    </a>
                                </h3>
                                <p class="text-sm text-on-surface-variant mt-1">
                                    {!! __('common.content_invited_as') !!} <strong>{{ __(ucfirst($invite->role)) }}</strong>
                                    @if($invite->invitedBy)
                                        {!! __('common.field_by_name', ['name' => e($invite->invitedBy?->name)]) !!}
                                    @endif
                                </p>
                                @if($invite->team->city)
                                    <p class="text-xs text-on-surface-variant/70 mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">location_on</span>
                                        {{ $invite->team->city }}{{ $invite->team->country ? ', ' . $invite->team->country : '' }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="acceptInvite({{ $invite->id }})"
                                        class="px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                                    {{ __('common.action_accept') }}
                                </button>
                                <button wire:click="declineInvite({{ $invite->id }})"
                                        class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors border border-outline-variant rounded-lg">
                                    {{ __('common.action_decline') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
