@section('title', __('profile.content_settings'))

<div class="py-6 sm:py-8" x-data="settingsTabs()" x-init="init()">

    {{-- Page Header --}}
    <div class="max-w-2xl mx-auto mb-6">
        <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('profile.content_settings') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('profile.action_manage_privacy_notifications_accounts') }}</p>
    </div>

    {{-- Tab Navigation --}}
    <div class="max-w-2xl mx-auto mb-6 sm:mb-8">
        <nav class="flex gap-1 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 scrollbar-none" role="tablist" aria-label="Settings sections">
            @php
                $tabConfig = [
                    'privacy' => ['label' => __('profile.content_privacy_settings'), 'icon' => 'shield'],
                    'notifications' => ['label' => __('notifications.content_notification_preferences'), 'icon' => 'notifications'],
                    'support' => ['label' => __('profile.title_support_tickets'), 'icon' => 'contact_support'],
                    'account' => ['label' => __('profile.field_linked_accounts'), 'icon' => 'settings'],
                ];
            @endphp
            @foreach($tabConfig as $tabId => $tab)
                <button
                    @click="setTab('{{ $tabId }}')"
                    :aria-selected="activeTab === '{{ $tabId }}'"
                    aria-controls="panel-{{ $tabId }}"
                    role="tab"
                    :class="activeTab === '{{ $tabId }}'
                        ? 'bg-primary text-on-primary shadow-ambient'
                        : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container'"
                    class="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">{{ $tab['icon'] }}</span>
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Panels --}}
    <div class="max-w-2xl mx-auto space-y-8">

        {{-- Flash Messages (shared across tabs) --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ session('success') }}
                </p>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- TAB: Privacy --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'privacy'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-privacy" aria-labelledby="tab-privacy">

            @if($privacySaved)
                <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                     class="rounded-lg bg-secondary-container p-4 mb-6" role="status" aria-live="polite">
                    <p class="text-sm text-on-secondary-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                        {{ __('profile.flash_profile_updated_successfully') }}
                    </p>
                </div>
            @endif

            <form wire:submit="savePrivacySettings" class="space-y-6">
                @include('livewire.profile.partials._privacy-form')

                {{-- Save --}}
                <div class="flex justify-end">
                    <button type="submit" wire:loading.attr="disabled" wire:target="savePrivacySettings"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium whitespace-nowrap">
                        {{-- Stable label so the redirect trigger stays in the DOM (M054).
                            wire:target scopes loading to this form only (multiple submit forms on this page). --}}
                        <span class="inline-flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" wire:loading.remove wire:target="savePrivacySettings" aria-hidden="true">save</span>
                            <span class="material-symbols-outlined text-base animate-spin" wire:loading wire:target="savePrivacySettings" aria-hidden="true" role="status" aria-label="{{ __('common.content_saving') }}">progress_activity</span>
                            {{ __('common.action_save_changes') }}
                        </span>
                    </button>
                </div>
            </form>
        </div>

        {{-- ============================================================ --}}
        {{-- TAB: Notifications --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'notifications'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-notifications" aria-labelledby="tab-notifications">

            @if($notificationSaved)
                <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                     class="rounded-lg bg-secondary-container p-4 mb-6" role="status" aria-live="polite">
                    <p class="text-sm text-on-secondary-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                        {{ __('notifications.flash_notification_preferences_saved') }}
                    </p>
                </div>
            @endif

            <form wire:submit="saveNotificationSettings" class="space-y-6">
                @include('livewire.profile.partials._notification-form')

                {{-- Save --}}
                <div class="flex justify-end">
                    <button type="submit" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                        <span class="material-symbols-outlined text-base" wire:loading.remove aria-hidden="true">save</span>
                        <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
                        <span wire:loading class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                            {{ __('common.content_saving') }}
                        </span>
                    </button>
                </div>
            </form>
        </div>

        {{-- ============================================================ --}}
        {{-- TAB: Support Tickets --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'support'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-support" aria-labelledby="tab-support">

            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">contact_support</span>
                        {{ __('profile.title_your_tickets') }}
                    </h2>
                    @can('escalated-customer')
                        <a href="{{ route('escalated.customer.tickets.create') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary text-on-primary rounded-lg text-sm font-medium hover:brightness-110 active:scale-[0.96] transition-all">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                            {{ __('profile.action_new_ticket') }}
                        </a>
                    @endcan
                </div>

                @if($tickets->isEmpty())
                    <p class="text-sm text-on-surface-variant py-6 text-center">
                        {{ __('profile.content_no_support_tickets') }}
                    </p>
                @else
                    <div class="space-y-2">
                        @foreach($tickets as $ticket)
                            <a href="{{ route('escalated.customer.tickets.show', $ticket->reference) }}"
                               class="block rounded-lg border border-outline-variant hover:border-primary/50 hover:bg-surface-container transition-colors p-3 group">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-on-surface truncate group-hover:text-primary transition-colors">
                                            {{ $ticket->subject }}
                                        </p>
                                        <p class="text-xs text-on-surface-variant mt-0.5">
                                            {{ $ticket->reference }} &middot; {{ $ticket->updated_at->diffForHumans() }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full
                                        {{ $ticket->status->isOpen()
                                            ? 'bg-primary-container text-on-primary-container'
                                            : 'bg-surface-container-high text-on-surface-variant' }}">
                                        @if($ticket->status->isOpen())
                                            <span class="inline-block w-2 h-2 rounded-full bg-current" aria-hidden="true"></span>
                                        @else
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true"
                                                  style="font-variation-settings: 'FILL' 1">check_circle</span>
                                        @endif
                                        {{ $ticket->status->label() }}
                                    </span>
                                </div>
                            </a>
                        @endforeach
                    </div>

                    @if($tickets->count() >= 20)
                        <div class="mt-4 text-center">
                            <a href="{{ route('escalated.customer.tickets.index') }}"
                               class="text-sm text-primary hover:underline">
                                {{ __('profile.action_view_all_tickets') }} &rarr;
                            </a>
                        </div>
                    @endif
                @endif
            </section>
        </div>

        {{-- ============================================================ --}}
        {{-- TAB: Account (Linked Accounts, Privacy & Data, Password, Danger Zone) --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'account'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-account" aria-labelledby="tab-account">

            <div class="space-y-6">
                @include('livewire.profile.partials._linked-accounts')

                {{-- Calendar Feed (per-user iCal token — D123) --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">calendar_today</span>
                        {{ __('settings.calendar_feed_title') }}
                    </h2>
                    <p class="text-sm text-on-surface-variant mb-4">
                        {{ __('settings.calendar_feed_description') }}
                    </p>

                    @if(session()->has('calendar_feed_generated'))
                        <div class="rounded-lg bg-secondary-container p-4 mb-4" role="status" aria-live="polite">
                            <p class="text-sm text-on-secondary-container flex items-center gap-2">
                                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                                {{ session('calendar_feed_generated') }}
                            </p>
                        </div>
                    @endif

                    @if(session()->has('calendar_feed_revoked'))
                        <div class="rounded-lg bg-secondary-container p-4 mb-4" role="status" aria-live="polite">
                            <p class="text-sm text-on-secondary-container flex items-center gap-2">
                                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                                {{ session('calendar_feed_revoked') }}
                            </p>
                        </div>
                    @endif

                    @if($calendarFeedUrl)
                        <div class="rounded-lg border border-outline-variant p-4 mb-4" x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.urlInput.value); } }">
                            <label for="calendar-feed-url" class="block text-xs font-medium text-on-surface-variant mb-1.5">
                                {{ __('settings.calendar_feed_url_label') }}
                            </label>
                            <div class="flex items-stretch gap-2">
                                <input id="calendar-feed-url" x-ref="urlInput" type="text" readonly
                                       value="{{ $calendarFeedUrl }}"
                                       class="flex-1 min-w-0 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface font-mono" />
                                <button type="button" @click="copy(); copied = true; setTimeout(() => copied = false, 1500)"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container transition-colors text-sm font-medium whitespace-nowrap">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">content_copy</span>
                                    <span x-show="!copied">{{ __('settings.calendar_feed_copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('settings.calendar_feed_copied') }}</span>
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-on-surface-variant">
                                {{ __('settings.calendar_feed_url_help') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button wire:click="generateCalendarFeedToken" wire:loading.attr="disabled" wire:confirm="{{ __('settings.calendar_feed_regenerate_confirm') }}"
                                    class="inline-flex items-center gap-2 px-4 py-2.5 border border-outline-variant text-on-surface-variant rounded-lg hover:bg-surface-container-high transition-colors text-sm font-medium">
                                <span class="material-symbols-outlined text-base" aria-hidden="true" wire:loading.remove wire:target="generateCalendarFeedToken">refresh</span>
                                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true" wire:loading wire:target="generateCalendarFeedToken" role="status">progress_activity</span>
                                <span>{{ __('settings.calendar_feed_regenerate') }}</span>
                            </button>
                            <button wire:click="revokeCalendarFeedToken" wire:loading.attr="disabled" wire:confirm="{{ __('settings.calendar_feed_revoke_confirm') }}"
                                    class="inline-flex items-center gap-2 px-4 py-2.5 border border-error/40 text-error rounded-lg hover:bg-error-container/50 transition-colors text-sm font-medium">
                                <span class="material-symbols-outlined text-base" aria-hidden="true" wire:loading.remove wire:target="revokeCalendarFeedToken">link_off</span>
                                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true" wire:loading wire:target="revokeCalendarFeedToken" role="status">progress_activity</span>
                                <span>{{ __('settings.calendar_feed_revoke') }}</span>
                            </button>
                        </div>
                    @else
                        <button wire:click="generateCalendarFeedToken" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                            <span class="material-symbols-outlined text-base" aria-hidden="true" wire:loading.remove wire:target="generateCalendarFeedToken">add</span>
                            <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true" wire:loading wire:target="generateCalendarFeedToken" role="status">progress_activity</span>
                            <span>{{ __('settings.calendar_feed_generate') }}</span>
                        </button>
                    @endif
                </section>

                {{-- Discord Servers (landlord surface — guilds this user installed) --}}
                @include('livewire.profile.partials._discord-servers')

                {{-- Privacy & Data: Data Export Request --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">shield</span>
                        {{ __('profile.title_privacy_and_data') }}
                    </h2>
                    <p class="text-sm text-on-surface-variant mb-4">
                        {{ __('profile.content_request_your_data_description') }}
                    </p>

                    @if(session()->has('data_export_requested'))
                        <div class="rounded-lg bg-secondary-container p-4 mb-4" role="status" aria-live="polite">
                            <p class="text-sm text-on-secondary-container flex items-center gap-2">
                                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                                {{ session('data_export_requested') }}
                            </p>
                        </div>
                    @endif

                    @error('dataExport')
                        <div class="rounded-lg bg-error-container p-4 mb-4" role="alert">
                            <p class="text-sm text-on-error-container flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">error</span>
                                {{ $message }}
                            </p>
                        </div>
                    @enderror

                    @if($hasPendingExportRequest)
                        <div class="rounded-lg bg-surface-container-high p-4 flex items-center gap-3">
                            <span class="material-symbols-outlined text-lg text-on-surface-variant animate-spin" aria-hidden="true">progress_activity</span>
                            <p class="text-sm text-on-surface-variant">
                                {{ __('profile.content_data_export_pending') }}
                            </p>
                        </div>
                    @else
                        <button wire:click="requestExport" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 px-4 py-2.5 border border-outline-variant text-on-surface-variant rounded-lg hover:bg-surface-container-high transition-colors text-sm font-medium">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">download</span>
                            <span wire:loading.remove>{{ __('profile.action_request_my_data') }}</span>
                            <span wire:loading class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                                {{ __('common.content_saving') }}
                            </span>
                        </button>
                    @endif
                </section>

                @include('livewire.profile.partials._password-form')
                @include('livewire.profile.partials._danger-zone')
            </div>
        </div>

    </div>{{-- /max-w-2xl --}}
</div>
