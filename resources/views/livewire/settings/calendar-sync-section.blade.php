@php($connection = $this->connection)
@php($refusalReason = $this->refusalReason)
@php($testMode = $this->testModeActive)
@php($isDisconnecting = $connection !== null && $connection->status === \App\Models\GoogleCalendarConnection::STATUS_DISCONNECTING)

<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6" aria-labelledby="calendar-sync-heading">
    <h2 id="calendar-sync-heading" class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">sync</span>
        {{ __('calendar_sync.heading_connect') }}
    </h2>
    <p class="text-sm text-on-surface-variant mb-4">
        {{ __('calendar_sync.content_intro') }}
    </p>

    {{-- Gate-refusal flash from the S02-T02 connect/callback routes: the
         controller flashes the calendar_sync_refusal marker and redirects
         back here — the honest copy renders in the section it belongs to. --}}
    @if(session()->has('calendar_sync_refusal'))
        <div class="rounded-lg bg-error-container p-4 mb-4" role="alert">
            <p class="text-sm text-on-error-container flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                {{ session('calendar_sync_refusal') === 'not_allowlisted'
                    ? __('calendar_sync.error_connect_refused_not_allowlisted')
                    : __('calendar_sync.error_connect_refused_disabled') }}
            </p>
        </div>
    @endif

    @if($connection !== null)
        {{-- Status row: linked Google account + honest status + limited-rollout
             badge + last sync (format_date, K002). --}}
        <div class="rounded-lg border border-outline-variant p-4 mb-4">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span class="text-sm font-medium text-on-surface truncate">{{ $connection->google_account_email }}</span>
                <span class="shrink-0 inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full
                    {{ $connection->status === \App\Models\GoogleCalendarConnection::STATUS_REVOKED
                        ? 'bg-error-container text-on-error-container'
                        : ($isDisconnecting
                            ? 'bg-surface-container-high text-on-surface-variant'
                            : 'bg-primary-container text-on-primary-container') }}">
                    <span class="inline-block w-2 h-2 rounded-full bg-current" aria-hidden="true"></span>
                    {{ $connection->status === \App\Models\GoogleCalendarConnection::STATUS_REVOKED
                        ? __('calendar_sync.status_revoked')
                        : ($isDisconnecting
                            ? __('calendar_sync.status_disconnecting')
                            : __('calendar_sync.status_connected')) }}
                </span>
                @if($testMode)
                    <span class="shrink-0 inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-surface-container-high text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">science</span>
                        {{ __('calendar_sync.label_mode_test_user_note') }}
                    </span>
                @endif
            </div>
            @if($connection->last_synced_at !== null)
                <p class="text-xs text-on-surface-variant mt-1.5">
                    {{ __('calendar_sync.status_last_sync', ['time' => format_date($connection->last_synced_at, 'datetime')]) }}
                </p>
            @endif
        </div>

        {{-- Revoked banner: access was revoked at Google — reconnect to
             resume (reconnect replaces the row and preserves the toggles). --}}
        @if($connection->status === \App\Models\GoogleCalendarConnection::STATUS_REVOKED)
            <div class="rounded-lg bg-error-container p-4 mb-4" role="alert">
                <p class="text-sm text-on-error-container flex items-start gap-2">
                    <span class="material-symbols-outlined text-base shrink-0" aria-hidden="true">sync_problem</span>
                    {{ __('calendar_sync.content_banner_revoked') }}
                </p>
                @if($refusalReason === null)
                    <a href="{{ route('settings.calendar.google.connect') }}"
                       class="mt-3 inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">refresh</span>
                        {{ __('calendar_sync.action_connect') }}
                    </a>
                @endif
            </div>
        @endif

        {{-- Disconnecting: the remove-disposition purge job owns the calendar
             (delete events → revoke → row delete). The source toggles are
             meaningless in this state, so they hide; the status badge and
             this note tell the truth while it runs. --}}
        @if($isDisconnecting)
            <div class="rounded-lg bg-surface-container-low p-4 mb-4" role="status">
                <p class="text-sm text-on-surface-variant flex items-center gap-2">
                    <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                    {{ __('calendar_sync.content_disconnecting') }}
                </p>
            </div>
        @else
            {{-- Per-source toggle rows: exactly the sources S01's consent copy
                 documents (SOURCE_TOGGLES), each with its own switch. --}}
            <fieldset class="mb-4">
                <div class="space-y-2">
                    @foreach(\App\Models\GoogleCalendarConnection::SOURCE_TOGGLES as $source)
                        @php($sourceEnabled = $sourceToggles[$source] ?? false)
                        <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg gap-2 sm:gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-on-surface">{{ __("calendar_sync.label_source_{$source}") }}</p>
                                <p class="text-xs text-on-surface-variant mt-0.5">{{ __("calendar_sync.description_source_{$source}") }}</p>
                            </div>
                            <button type="button"
                                    wire:click="toggleSource('{{ $source }}')"
                                    wire:loading.attr="disabled" wire:target="toggleSource('{{ $source }}')"
                                    @class([
                                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                                        'bg-primary' => $sourceEnabled,
                                        'bg-surface-container-highest' => ! $sourceEnabled,
                                    ])
                                    role="switch"
                                    aria-label="{{ __("calendar_sync.label_source_{$source}") }}"
                                    :aria-checked="{{ $sourceEnabled ? 'true' : 'false' }}">
                                <span @class([
                                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-xs',
                                    'translate-x-6' => $sourceEnabled,
                                    'translate-x-1' => ! $sourceEnabled,
                                ])></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endif

        {{-- Disconnect confirmation: the disposition is the user's explicit
             choice — keep the events (default, pre-selected) or remove every
             Roundup-managed event before the grant is revoked. --}}
        @if($disconnectDialogOpen && ! $isDisconnecting)
            <div class="rounded-lg border border-error/40 p-4 mb-4" role="dialog" aria-labelledby="calendar-disconnect-heading">
                <h3 id="calendar-disconnect-heading" class="text-sm font-medium text-on-surface mb-3">
                    {{ __('calendar_sync.confirm_disconnect') }}
                </h3>
                <div class="space-y-2 mb-4">
                    <label class="flex items-start gap-3 p-3 bg-surface-container-low rounded-lg cursor-pointer">
                        <input type="radio" name="disconnect-disposition" value="keep" wire:model="disconnectDisposition" class="mt-1 accent-primary">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-on-surface">{{ __('calendar_sync.label_disconnect_keep') }}</span>
                            <span class="block text-xs text-on-surface-variant mt-0.5">{{ __('calendar_sync.description_disconnect_keep') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 p-3 bg-surface-container-low rounded-lg cursor-pointer">
                        <input type="radio" name="disconnect-disposition" value="remove" wire:model="disconnectDisposition" class="mt-1 accent-primary">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-on-surface">{{ __('calendar_sync.label_disconnect_remove') }}</span>
                            <span class="block text-xs text-on-surface-variant mt-0.5">{{ __('calendar_sync.description_disconnect_remove') }}</span>
                        </span>
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button wire:click="confirmDisconnect" wire:loading.attr="disabled" wire:target="confirmDisconnect"
                            class="inline-flex items-center gap-2 px-4 py-2.5 bg-error text-on-error rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">link_off</span>
                        {{ __('calendar_sync.action_disconnect_confirm') }}
                    </button>
                    <button type="button" wire:click="cancelDisconnect"
                            class="px-4 py-2.5 text-sm font-medium text-on-surface-variant hover:text-on-surface transition-colors">
                        {{ __('calendar_sync.action_disconnect_cancel') }}
                    </button>
                </div>
            </div>
        @endif

        {{-- Disconnect: opens the keep/remove confirmation — never a silent
             either/or. Stays available while disconnecting as the retry
             path for a purge that dead-lettered. --}}
        <button wire:click="disconnect" wire:loading.attr="disabled" wire:target="disconnect"
                class="inline-flex items-center gap-2 px-4 py-2.5 border border-error/40 text-error rounded-lg hover:bg-error-container/50 transition-colors text-sm font-medium">
            <span class="material-symbols-outlined text-base" aria-hidden="true" wire:loading.remove wire:target="disconnect">link_off</span>
            <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true" wire:loading wire:target="disconnect" role="status">progress_activity</span>
            <span>{{ __('calendar_sync.action_disconnect') }}</span>
        </button>
    @else
        {{-- Unconnected: connect CTA (or the inert refusal while the gate is
             closed — no route hop, honest copy instead). --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            @if($refusalReason === null)
                <a href="{{ route('settings.calendar.google.connect') }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add_link</span>
                    {{ __('calendar_sync.action_connect') }}
                </a>
            @else
                <button type="button" disabled aria-disabled="true"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient text-sm font-medium opacity-50 cursor-not-allowed">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add_link</span>
                    {{ __('calendar_sync.action_connect') }}
                </button>
            @endif
            @if($testMode)
                <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-surface-container-high text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">science</span>
                    {{ __('calendar_sync.label_mode_test_user_note') }}
                </span>
            @endif
        </div>
        @if($refusalReason !== null)
            <p class="text-sm text-on-surface-variant mb-4" role="note">
                {{ $refusalReason === 'not_allowlisted'
                    ? __('calendar_sync.error_connect_refused_not_allowlisted')
                    : __('calendar_sync.error_connect_refused_disabled') }}
            </p>
        @endif
    @endif

    {{-- Honest-scope disclosure (always available — claims stay aligned with
         the Google user-data disclosure in privacy.php), plus the disconnect
         consequences while connected. --}}
    <details class="mt-2">
        <summary class="cursor-pointer select-none text-sm font-medium text-on-surface-variant hover:text-on-surface transition-colors">
            {{ __('calendar_sync.heading_scope') }}
        </summary>
        <div class="mt-3 space-y-2 text-sm text-on-surface-variant border-l-2 border-outline-variant pl-4">
            <p>{{ __('calendar_sync.content_scope_does') }}</p>
            <p>{{ __('calendar_sync.content_scope_never_reads') }}</p>
            <p>{{ __('calendar_sync.content_scope_never_notifies') }}</p>
            <p>{{ __('calendar_sync.content_scope_storage') }}</p>
            @if($connection !== null)
                <div class="pt-2">
                    <h3 class="text-sm font-medium text-on-surface">{{ __('calendar_sync.heading_disconnect') }}</h3>
                    <p class="mt-1">{{ __('calendar_sync.content_disconnect_consequences') }}</p>
                </div>
            @endif
        </div>
    </details>
</section>
