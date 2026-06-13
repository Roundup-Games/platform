{{-- Notification Preferences --}}
{{-- Legend --}}
<div class="bg-primary/5 border border-primary/10 rounded-xl p-4 flex gap-3">
    <span class="material-symbols-outlined text-lg text-primary mt-0.5 shrink-0" aria-hidden="true">info</span>
    <p class="text-sm text-on-surface-variant">{{ __('notifications.hint_preference_channels') }}</p>
</div>

@if($notificationSaved)
    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
         class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
        <p class="text-sm text-on-secondary-container flex items-center gap-2">
            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
            {{ __('notifications.flash_notification_preferences_saved') }}
        </p>
    </div>
@endif

{{-- Channel header (visible on sm+) --}}
<div class="hidden sm:grid sm:grid-cols-[1fr_repeat(3,minmax(0,80px))] gap-2 px-4">
    <span></span>
    <span class="text-xs font-medium text-on-surface-variant text-center">{{ __('notifications.channel_in_app') }}</span>
    <span class="text-xs font-medium text-on-surface-variant text-center">{{ __('notifications.channel_email') }}</span>
    <span class="text-xs font-medium text-on-surface-variant text-center">{{ __('notifications.channel_push') }}</span>
</div>

@foreach(\App\Enums\NotificationCategory::grouped() as $groupKey => $group)
    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
        <h2 class="text-sm font-heading font-semibold tracking-tight text-on-surface-variant mb-3 uppercase">
            {{ $group['label'] }}
        </h2>
        <div class="space-y-2">
            @foreach($group['options'] as $categoryValue => $categoryLabel)
                @php
                    $db = $notificationSettings[$categoryValue]['database'] ?? true;
                    $mail = $notificationSettings[$categoryValue]['mail'] ?? false;
                    $push = $notificationSettings[$categoryValue]['push'] ?? false;
                @endphp
                <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg gap-2 sm:gap-3">
                    <span class="text-sm font-medium text-on-surface min-w-0 truncate">{{ $categoryLabel }}</span>
                    <div class="flex items-center gap-3 shrink-0">
                        {{-- In-App toggle --}}
                        <button type="button"
                                wire:click="$toggle('notificationSettings.{{ $categoryValue }}.database')"
                                @class([
                                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                                    'bg-primary' => $db,
                                    'bg-surface-container-highest' => !$db,
                                ])
                                role="switch"
                                aria-label="{{ $categoryLabel }} — {{ __('notifications.channel_in_app') }}"
                                :aria-checked="{{ $db ? 'true' : 'false' }}">
                            <span @class([
                                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-xs',
                                'translate-x-6' => $db,
                                'translate-x-1' => !$db,
                            ])></span>
                        </button>

                        {{-- Email toggle --}}
                        <button type="button"
                                wire:click="$toggle('notificationSettings.{{ $categoryValue }}.mail')"
                                @class([
                                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                                    'bg-primary' => $mail,
                                    'bg-surface-container-highest' => !$mail,
                                ])
                                role="switch"
                                aria-label="{{ $categoryLabel }} — {{ __('notifications.channel_email') }}"
                                :aria-checked="{{ $mail ? 'true' : 'false' }}">
                            <span @class([
                                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-xs',
                                'translate-x-6' => $mail,
                                'translate-x-1' => !$mail,
                            ])></span>
                        </button>

                        {{-- Push toggle --}}
                        <button type="button"
                                wire:click="$toggle('notificationSettings.{{ $categoryValue }}.push')"
                                @class([
                                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                                    'bg-primary' => $push,
                                    'bg-surface-container-highest' => !$push,
                                ])
                                role="switch"
                                aria-label="{{ $categoryLabel }} — {{ __('notifications.channel_push') }}"
                                :aria-checked="{{ $push ? 'true' : 'false' }}">
                            <span @class([
                                'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-xs',
                                'translate-x-6' => $push,
                                'translate-x-1' => !$push,
                            ])></span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endforeach

{{-- Push Subscription Management --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-sm font-heading font-semibold tracking-tight text-on-surface-variant mb-3 uppercase">
        {{ __('notifications.push_devices_heading') }}
    </h2>

    {{-- Unsupported browser --}}
    <div data-push-ui="unsupported" class="hidden">
        <div class="flex items-center gap-3 p-4 bg-surface-container-low rounded-lg">
            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">block</span>
            <p class="text-sm text-on-surface-variant">{{ __('notifications.push_not_supported') }}</p>
        </div>
    </div>

    {{-- Default state: no subscription --}}
    <div data-push-ui="default" class="hidden">
        <div class="flex items-center justify-between p-4 bg-surface-container-low rounded-lg">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">notifications_off</span>
                <p class="text-sm text-on-surface">{{ __('notifications.push_enable_description') }}</p>
            </div>
            <button type="button" data-push="subscribe"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                <span class="material-symbols-outlined text-base" aria-hidden="true">notifications_active</span>
                {{ __('notifications.push_enable_button') }}
            </button>
        </div>
    </div>

    {{-- Subscribed state --}}
    <div data-push-ui="subscribed" class="hidden">
        <div class="flex items-center justify-between p-4 bg-surface-container-low rounded-lg">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">notifications_active</span>
                <p class="text-sm text-on-surface">
                    {{ __('pwa.push_enabled_on_devices', ['count' => $pushSubscriptionCount]) }}
                </p>
            </div>
            <button type="button" data-push="unsubscribe"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-error-container text-on-error-container rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                <span class="material-symbols-outlined text-base" aria-hidden="true">link_off</span>
                {{ __('notifications.push_disable_button') }}
            </button>
        </div>
    </div>

    {{-- Permission denied state --}}
    <div data-push-ui="denied" class="hidden">
        <div class="flex items-center gap-3 p-4 bg-error-container/30 rounded-lg">
            <span class="material-symbols-outlined text-error" aria-hidden="true">notifications_paused</span>
            <p class="text-sm text-on-surface">{{ __('pwa.push_denied_hint') }}</p>
        </div>
    </div>
</section>

@error('notificationSettings') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror
