<div class="py-6 sm:py-8" x-data="profileTabs()" x-init="init()">

    {{-- Page Header --}}
    <div class="max-w-2xl mx-auto mb-6">
        <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('profile.content_my_profile') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('profile.action_manage_your_account_information_preferences') }}</p>
    </div>

    {{-- Tab Navigation --}}
    <div class="max-w-2xl mx-auto mb-6 sm:mb-8">
        <nav class="flex gap-1 overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 scrollbar-none" role="tablist" aria-label="Profile sections">
            @php
                $tabConfig = [
                    'profile' => ['label' => __('profile.field_avatar'), 'icon' => 'person'],
                    'preferences' => ['label' => __('games.content_game_preferences'), 'icon' => 'casino'],
                    'privacy' => ['label' => __('profile.content_privacy_settings'), 'icon' => 'shield'],
                    'notifications' => ['label' => __('notifications.content_notification_preferences'), 'icon' => 'notifications'],
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
        {{-- TAB: Profile --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'profile'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-profile" aria-labelledby="tab-profile">

            @if($saved)
                <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                     class="rounded-lg bg-secondary-container p-4 mb-6" role="status" aria-live="polite">
                    <p class="text-sm text-on-secondary-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                        {{ __('profile.flash_profile_updated_successfully') }}
                    </p>
                </div>
            @endif

            @include('livewire.profile.partials._avatar-upload')

            <form wire:submit="saveProfile" class="space-y-6">
                @include('livewire.profile.partials._personal-info')

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
        {{-- TAB: Preferences --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'preferences'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-preferences" aria-labelledby="tab-preferences">

            @if($preferencesSaved)
                <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                     class="rounded-lg bg-secondary-container p-4 mb-6" role="status" aria-live="polite">
                    <p class="text-sm text-on-secondary-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                        {{ __('profile.flash_profile_updated_successfully') }}
                    </p>
                </div>
            @endif

            <form wire:submit="savePreferences" class="space-y-6">
                @include('livewire.profile.partials._game-system-preferences')
                @include('livewire.profile.partials._vibe-preferences')

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
        {{-- TAB: Privacy --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'privacy'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-privacy" aria-labelledby="tab-privacy">

            <form wire:submit="savePrivacySettings" class="space-y-6">
                @include('livewire.profile.partials._privacy-form')

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
        {{-- TAB: Notifications --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'notifications'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-notifications" aria-labelledby="tab-notifications">

            <form wire:submit="saveNotificationSettings" class="space-y-6">
                @if($notificationSaved)
                    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                         class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                        <p class="text-sm text-on-secondary-container flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                            {{ __('notifications.flash_notification_preferences_saved') }}
                        </p>
                    </div>
                @endif

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
        {{-- TAB: Account --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'account'" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             role="tabpanel" id="panel-account" aria-labelledby="tab-account">

            <div class="space-y-6">
                @include('livewire.profile.partials._linked-accounts')
                @include('livewire.profile.partials._password-form')
                @include('livewire.profile.partials._danger-zone')
            </div>
        </div>

    </div>{{-- /max-w-2xl --}}
</div>
