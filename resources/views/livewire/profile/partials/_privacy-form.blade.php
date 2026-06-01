{{-- Privacy Settings Form --}}
{{-- Intro card --}}
<div class="bg-primary/5 border border-primary/10 rounded-xl p-4 flex gap-3">
    <span class="material-symbols-outlined text-lg text-primary mt-0.5 shrink-0" aria-hidden="true">info</span>
    <p class="text-sm text-on-surface-variant">{{ __('profile.action_control_who_sees_your_profile_information') }}</p>
</div>

@if($privacySaved)
    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
         class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
        <p class="text-sm text-on-secondary-container flex items-center gap-2">
            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
            {{ __('profile.flash_privacy_settings_saved') }}
        </p>
    </div>
@endif

<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">shield</span>
        {{ __('profile.content_privacy_settings') }}
    </h2>

    <div class="space-y-3">
        @php
            $fieldLabels = [
                'location' => __('location.content_location'),
                'game_systems' => __('games.content_game_systems'),
                'vibes' => __('profile.content_vibes'),
                'campaigns' => __('profile.content_sessions_and_campaigns'),
                'teams' => __('profile.content_teams'),
                'friends_list' => __('profile.content_friends_list'),
                'stats' => __('profile.content_reliability_stats'),
            ];
            $fieldIcons = [
                'location' => 'location_on',
                'game_systems' => 'casino',
                'vibes' => 'mood',
                'campaigns' => 'event_note',
                'teams' => 'groups',
                'friends_list' => 'group',
                'stats' => 'verified',
            ];
            $fieldDescriptions = [
                'location' => __('profile.content_who_can_see_your_location'),
                'game_systems' => __('profile.content_who_can_see_your_game_systems'),
                'vibes' => __('profile.content_who_can_see_your_vibes'),
                'campaigns' => __('profile.content_who_can_see_your_sessions_and_campaigns'),
                'teams' => __('profile.content_who_can_see_your_teams'),
                'friends_list' => __('profile.content_who_can_see_your_friends_list'),
                'stats' => __('profile.content_who_can_see_your_reliability_stats'),
            ];
        @endphp

        @foreach(\App\Services\ProfileVisibilityResolver::FIELDS as $field)
            <div class="p-3 sm:p-4 bg-surface-container-low rounded-lg">
                {{-- Desktop: single row with label left, toggle right --}}
                <div class="hidden sm:flex sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant shrink-0" aria-hidden="true">{{ $fieldIcons[$field] ?? 'info' }}</span>
                        <div class="min-w-0">
                            <span class="text-sm font-medium text-on-surface">{{ $fieldLabels[$field] ?? $field }}</span>
                            @if(isset($fieldDescriptions[$field]))
                                <p class="text-xs text-on-surface-variant mt-0.5">{{ $fieldDescriptions[$field] }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex rounded-lg overflow-hidden border border-outline-variant/30 shrink-0">
                        @foreach(['everyone' => __('profile.visibility_everyone'), 'friends' => __('profile.visibility_friends'), 'nobody' => __('profile.visibility_nobody')] as $value => $label)
                            @php
                                $isActive = ($privacySettings[$field] ?? 'everyone') === $value;
                            @endphp
                            <button type="button"
                                    wire:click="$set('privacySettings.{{ $field }}', '{{ $value }}')"
                                    @class([
                                        'px-3 py-1.5 text-xs font-medium transition-colors',
                                        'bg-primary text-on-primary' => $isActive,
                                        'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => !$isActive,
                                    ])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Mobile: icon + label row, full-width toggle below --}}
                <div class="sm:hidden space-y-2">
                    <div class="flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant shrink-0" aria-hidden="true">{{ $fieldIcons[$field] ?? 'info' }}</span>
                        <span class="text-sm font-medium text-on-surface">{{ $fieldLabels[$field] ?? $field }}</span>
                    </div>

                    <div class="flex rounded-lg overflow-hidden border border-outline-variant/30">
                        @foreach(['everyone' => __('profile.visibility_everyone'), 'friends' => __('profile.visibility_friends'), 'nobody' => __('profile.visibility_nobody')] as $value => $label)
                            @php
                                $isActive = ($privacySettings[$field] ?? 'everyone') === $value;
                            @endphp
                            <button type="button"
                                    wire:click="$set('privacySettings.{{ $field }}', '{{ $value }}')"
                                    @class([
                                        'flex-1 py-2 text-xs font-medium text-center transition-colors',
                                        'bg-primary text-on-primary' => $isActive,
                                        'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => !$isActive,
                                    ])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @error('privacySettings') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror
</section>
