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

            {{-- Avatar --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 mb-6">
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">account_circle</span>
                    {{ __('profile.field_avatar') }}
                </h2>

                <div class="flex items-center gap-5">
                    <div class="shrink-0">
                        <x-user-avatar :user="auth()->user()" size="w-16 h-16 sm:w-20 sm:h-20" text-size="text-xl sm:text-2xl" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="cursor-pointer inline-flex items-center gap-1.5 px-3.5 py-2 bg-surface-container-high text-on-surface-variant rounded-lg text-sm font-medium hover:bg-surface-container transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">upload</span>
                                <span>{{ __('common.field_choose_photo') }}</span>
                                <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                            </label>

                            @php $avatarMedia = auth()->user()->getFirstMedia('avatar'); @endphp
                            @if($avatarMedia)
                                <button wire:click="removeAvatar" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 text-sm text-error hover:brightness-110 transition-colors">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">delete</span>
                                    {{ __('common.action_remove') }}
                                </button>
                            @endif
                        </div>

                        @error('avatar')
                            <p class="mt-2 text-sm text-error">{{ $message }}</p>
                        @enderror

                        @if($avatar)
                            <div class="mt-3 flex items-center gap-3">
                                @php
                                    $previewUrl = null;
                                    try { $previewUrl = $avatar->temporaryUrl(); } catch (\Throwable $e) {}
                                @endphp
                                @if($previewUrl)
                                    <img src="{{ $previewUrl }}" alt="Preview" class="w-12 h-12 rounded-full object-cover ring-2 ring-outline-variant/30" />
                                @endif
                                <span class="text-xs text-on-surface-variant truncate">{{ $avatar->getClientOriginalName() }}</span>
                            </div>
                        @endif

                        <p class="mt-2 text-xs text-on-surface-variant/60">{{ __('common.content_jpg_png_or_gif_max_1mb') }}</p>
                    </div>
                </div>
            </section>

            {{-- Profile Form --}}
            <form wire:submit="saveProfile" class="space-y-6">
                {{-- Personal Info --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">badge</span>
                        {{ __('common.content_personal_information') }}
                    </h2>

                    <div class="space-y-4">
                        <div>
                            <label for="profile-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_name') }}</label>
                            <input type="text" id="profile-name" wire:model="name"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                            @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="profile-email" class="block text-sm font-medium text-on-surface mb-1">{{ __('emails.field_email') }}</label>
                            <input type="email" id="profile-email" wire:model="email"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                            @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="profile-gender" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_gender') }}</label>
                                <select id="profile-gender" wire:model="gender"
                                        class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                                    <option value="">{{ __('common.content_select') }}</option>
                                    <option value="male">{{ __('common.content_male') }}</option>
                                    <option value="female">{{ __('common.content_female') }}</option>
                                    <option value="non-binary">{{ __('common.content_non_binary') }}</option>
                                    <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                                    <option value="other">{{ __('common.content_other') }}</option>
                                </select>
                            </div>

                            <div>
                                <label for="profile-pronouns" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.content_pronouns') }}</label>
                                <select id="profile-pronouns" wire:model="pronouns"
                                        class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                                    <option value="">{{ __('common.content_select') }}</option>
                                    <option value="he/him">{{ __('common.content_he_him') }}</option>
                                    <option value="she/her">{{ __('common.content_she_her') }}</option>
                                    <option value="they/them">{{ __('common.content_they_them') }}</option>
                                    <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                                    <option value="other">{{ __('common.content_other') }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="profile-phone" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_phone') }}</label>
                            <input type="tel" id="profile-phone" wire:model="phone" placeholder="+49 151 1234567"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant/50" />
                            @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </section>

                {{-- Language & Location --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">language</span>
                        {{ __('profile.content_language_location') }}
                    </h2>

                    <div class="space-y-4">
                        <div>
                            <label for="profile-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.content_preferred_language') }}</label>
                            <select id="profile-language" wire:model="preferredLanguage"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                                <option value="">{{ __('common.content_select') }}</option>
                                @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                    <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                                @endforeach
                            </select>
                            @error('preferredLanguage') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-on-surface mb-1">{{ __('location.content_location') }}</label>
                            <livewire:components.location-picker :location-id="$locationId" />
                        </div>
                    </div>
                </section>

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
                {{-- Game Preferences --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-1 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">casino</span>
                        {{ __('games.content_game_preferences') }}
                    </h2>
                    <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_select_the_games_you_enjoy_and_those") }}</p>

                    {{-- Favorite Games --}}
                    <div class="mb-6">
                        <h3 class="text-sm font-semibold text-on-surface mb-1 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">favorite</span>
                            {{ __('games.content_favorite_games') }}
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-3">{{ __("games.content_selecting_a_base_game_as_a_favorite_implies") }}</p>

                        <livewire:components.game-system-preference-picker
                            :wire:key="'picker-favorite'"
                            preferenceType="favorite"
                            :selectedIds="$favoriteGameSystemIds"
                            :conflictIds="$avoidedGameSystemIds"
                        />
                    </div>

                    {{-- Games to Avoid --}}
                    <div>
                        <h3 class="text-sm font-semibold text-on-surface mb-1 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm text-error" style="font-variation-settings: 'FILL' 1" aria-hidden="true">block</span>
                            {{ __('games.content_games_to_avoid') }}
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-3">{{ __("profile.content_avoid_preferences_take_priority_over_favorites") }}</p>

                        <livewire:components.game-system-preference-picker
                            :wire:key="'picker-avoid'"
                            preferenceType="avoid"
                            :selectedIds="$avoidedGameSystemIds"
                            :conflictIds="$favoriteGameSystemIds"
                        />
                    </div>

                    @error('favoriteGameSystemIds') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror
                    @error('avoidedGameSystemIds') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

                    <p class="mt-4 text-xs text-on-surface-variant">
                        {{ __('profile.content_favorites_favorite_avoids_avoided', [
                            'favorites' => count($favoriteGameSystemIds),
                            'avoids' => count($avoidedGameSystemIds),
                        ]) }}
                    </p>
                </section>

                {{-- Vibe Preferences --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-1 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">mood</span>
                        {{ __('profile.content_vibe_preferences') }}
                    </h2>
                    <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_tell_us_which_play_styles_you_enjoy") }}</p>

                    <livewire:components.vibe-preference-picker
                        :wire:key="'vibe-prefs'"
                        :preferences="$vibePreferences"
                    />

                    @php
                        $vibeFavorites = count(array_filter($vibePreferences, fn ($v) => $v === 'favorite'));
                        $vibeAvoids = count(array_filter($vibePreferences, fn ($v) => $v === 'avoid'));
                    @endphp
                    <p class="mt-4 text-xs text-on-surface-variant">
                        {{ __('profile.content_favorites_favorite_avoids_avoided', [
                            'favorites' => $vibeFavorites,
                            'avoids' => $vibeAvoids,
                        ]) }}
                    </p>
                </section>

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
                                'campaigns' => __('profile.content_campaigns'),
                                'teams' => __('profile.content_teams'),
                                'friends_list' => __('profile.content_friends_list'),
                            ];
                            $fieldIcons = [
                                'location' => 'location_on',
                                'game_systems' => 'casino',
                                'vibes' => 'mood',
                                'campaigns' => 'auto_stories',
                                'teams' => 'groups',
                                'friends_list' => 'group',
                            ];
                            $fieldDescriptions = [
                                'location' => __('profile.content_who_can_see_your_location'),
                                'game_systems' => __('profile.content_who_can_see_your_game_systems'),
                                'vibes' => __('profile.content_who_can_see_your_vibes'),
                                'campaigns' => __('profile.content_who_can_see_your_campaigns'),
                                'teams' => __('profile.content_who_can_see_your_teams'),
                                'friends_list' => __('profile.content_who_can_see_your_friends_list'),
                            ];
                        @endphp

                        @foreach(\App\Services\ProfileVisibilityResolver::FIELDS as $field)
                            <div class="p-3 sm:p-4 bg-surface-container-low rounded-lg">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="material-symbols-outlined text-lg text-on-surface-variant shrink-0" aria-hidden="true">{{ $fieldIcons[$field] ?? 'info' }}</span>
                                        <div class="min-w-0">
                                            <span class="text-sm font-medium text-on-surface">{{ $fieldLabels[$field] ?? $field }}</span>
                                            @if(isset($fieldDescriptions[$field]))
                                                <p class="text-xs text-on-surface-variant mt-0.5 hidden sm:block">{{ $fieldDescriptions[$field] }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Mobile: stack below; Desktop: inline --}}
                                    <div class="flex rounded-lg overflow-hidden border border-outline-variant/30 shrink-0">
                                        @foreach(['everyone' => __('profile.visibility_everyone'), 'friends' => __('profile.visibility_friends'), 'nobody' => __('profile.visibility_nobody')] as $value => $label)
                                            @php
                                                $isActive = ($privacySettings[$field] ?? 'everyone') === $value;
                                            @endphp
                                            <button type="button"
                                                    wire:click="$set('privacySettings.{{ $field }}', '{{ $value }}')"
                                                    @class([
                                                        'px-2.5 sm:px-3 py-1.5 text-xs font-medium transition-colors',
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

                {{-- Legend --}}
                <div class="bg-primary/5 border border-primary/10 rounded-xl p-4 flex gap-3">
                    <span class="material-symbols-outlined text-lg text-primary mt-0.5 shrink-0" aria-hidden="true">info</span>
                    <p class="text-sm text-on-surface-variant">{{ __('notifications.hint_preference_states') }}</p>
                </div>

                @php
                    $states = [
                        'off'    => ['label' => __('notifications.state_off'),    'icon' => 'notifications_off'],
                        'inapp'  => ['label' => __('notifications.state_in_app'), 'icon' => 'notifications'],
                        'all'    => ['label' => __('notifications.state_all'),    'icon' => 'notifications_active'],
                    ];
                @endphp

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
                                    $currentState = (!$db && !$mail) ? 'off' : ($db && !$mail ? 'inapp' : 'all');
                                @endphp
                                <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg gap-3">
                                    <span class="text-sm font-medium text-on-surface min-w-0">{{ $categoryLabel }}</span>
                                    <div class="flex rounded-lg overflow-hidden border border-outline-variant/30 shrink-0">
                                        @foreach($states as $stateKey => $state)
                                            @php
                                                $isActive = $currentState === $stateKey;
                                            @endphp
                                            <button type="button"
                                                    @if($stateKey === 'off')
                                                        wire:click="$set('notificationSettings.{{ $categoryValue }}.database', false); $set('notificationSettings.{{ $categoryValue }}.mail', false)"
                                                    @elseif($stateKey === 'inapp')
                                                        wire:click="$set('notificationSettings.{{ $categoryValue }}.database', true); $set('notificationSettings.{{ $categoryValue }}.mail', false)"
                                                    @else
                                                        wire:click="$set('notificationSettings.{{ $categoryValue }}.database', true); $set('notificationSettings.{{ $categoryValue }}.mail', true)"
                                                    @endif
                                                    @class([
                                                        'px-2 sm:px-2.5 py-1.5 text-xs font-medium transition-colors flex items-center gap-1',
                                                        'bg-primary text-on-primary' => $isActive,
                                                        'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => !$isActive,
                                                    ])
                                                    aria-label="{{ $categoryLabel }} — {{ $state['label'] }}"
                                                    :aria-pressed="{{ $isActive ? 'true' : 'false' }}">
                                                <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $state['icon'] }}</span>
                                                <span class="hidden sm:inline">{{ $state['label'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                @error('notificationSettings') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

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
                {{-- Linked Accounts --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">link</span>
                        {{ __('profile.field_linked_accounts') }}
                    </h2>

                    <div class="space-y-3">
                        @forelse($linkedAccounts as $linkedAccount)
                            <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                                <div class="flex items-center gap-3">
                                    @if($linkedAccount->provider === 'google')
                                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                                    @endif
                                    <div>
                                        <p class="text-sm font-medium text-on-surface capitalize">{{ $linkedAccount->provider }}</p>
                                        <p class="text-xs text-on-surface-variant">{{ __('common.field_connected_date', ['date' => format_date($linkedAccount->created_at, 'date')]) }}</p>
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                    <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                                    {{ __('common.content_connected') }}
                                </span>
                            </div>
                        @empty
                            <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                                    <div>
                                        <p class="text-sm font-medium text-on-surface">{{ __('common.content_google') }}</p>
                                        <p class="text-xs text-on-surface-variant">{{ __('common.content_not_connected') }}</p>
                                    </div>
                                </div>
                                <a href="{{ route('oauth.redirect', 'google') }}"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">add</span>
                                    {{ __('common.action_connect') }}
                                </a>
                            </div>
                        @endforelse
                    </div>
                </section>

                {{-- Password Section --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">lock</span>
                            {{ __('auth.field_password') }}
                        </h2>
                        @if(!$showPasswordForm)
                            <button wire:click="$set('showPasswordForm', true)"
                                    class="text-sm text-primary hover:brightness-110 transition-colors font-medium">
                                {{ $userHasPassword ? __('auth.field_change_password') : __('auth.field_set_password') }}
                            </button>
                        @endif
                    </div>

                    @if($showPasswordForm)
                        <form wire:submit="changePassword" class="space-y-4">
                            @if($userHasPassword)
                                <div>
                                    <label for="profile-current-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_current_password') }}</label>
                                    <input type="password" id="profile-current-password" wire:model="current_password" autocomplete="current-password"
                                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                                    @error('current_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div class="rounded-lg bg-primary/5 border border-primary/10 p-3">
                                    <p class="text-sm text-on-surface-variant flex items-start gap-2">
                                        <span class="material-symbols-outlined text-base text-primary mt-0.5 shrink-0" style="font-variation-settings: 'FILL' 1">info</span>
                                        {{ __('emails.content_your_account_was_created_via', ['provider' => $linkedAccounts->count() > 0 ? $linkedAccounts->first()->provider : __('common.content_a_third_party_provider')]) }}
                                    </p>
                                </div>
                            @endif

                            <div>
                                <label for="profile-new-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_new_password') }}</label>
                                <input type="password" id="profile-new-password" wire:model="password" autocomplete="new-password"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                                @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="profile-confirm-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_password') }}</label>
                                <input type="password" id="profile-confirm-password" wire:model="password_confirmation" autocomplete="new-password"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                            </div>

                            <div class="flex items-center gap-3 pt-2">
                                <button type="submit" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                                    <span wire:loading.remove>{{ $userHasPassword ? __('auth.field_update_password') : __('auth.field_set_password') }}</span>
                                    <span wire:loading>{{ $userHasPassword ? __('common.content_updating') : __('profile.content_setting') }}</span>
                                </button>
                                <button type="button" wire:click="$set('showPasswordForm', false)"
                                        class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                                    {{ __('common.action_cancel') }}
                                </button>
                            </div>
                        </form>
                    @else
                        @if($userHasPassword)
                            <p class="text-sm text-on-surface-variant">{{ __('auth.content_your_password_is_set_click') }}</p>
                        @else
                            <p class="text-sm text-on-surface-variant flex items-center gap-2">
                                <span class="material-symbols-outlined text-base text-amber-500" aria-hidden="true">warning</span>
                                {{ __('auth.content_no_password_set_you_currently') }}
                            </p>
                        @endif
                    @endif
                </section>

                @if(session('password_updated'))
                    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                         class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                        <p class="text-sm text-on-secondary-container flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                            {{ session('password_updated') }}
                        </p>
                    </div>
                @endif

                {{-- Danger Zone: Delete Account --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
                    <h2 class="text-lg font-heading font-semibold text-error mb-2 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">warning</span>
                        {{ __('profile.action_delete_account') }}
                    </h2>
                    <p class="text-sm text-on-surface-variant mb-4">
                        {{ __('profile.error_once_you_delete_your_account') }}
                    </p>

                    @if(!$showDeleteForm)
                        <button wire:click="$set('showDeleteForm', true)"
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">delete_forever</span>
                            {{ __('profile.action_delete_account') }}
                        </button>
                    @else
                        <div class="space-y-4 mt-4 pt-4 border-t border-error/20">
                            @if($userHasPassword)
                                <div>
                                    <label for="delete-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_your_password') }}</label>
                                    <input type="password" id="delete-password" wire:model="delete_password" autocomplete="current-password"
                                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                                    @error('delete_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label for="delete-confirm" class="block text-sm font-medium text-on-surface mb-1">
                                        {!! __('discovery.content_type_word_to_confirm', ['word' => '<strong class="text-error">DELETE</strong>']) !!}
                                    </label>
                                    <input type="text" id="delete-confirm" wire:model="delete_confirmation" autocomplete="off"
                                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                                    @error('delete_confirmation') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            <div class="flex items-center gap-3">
                                <button wire:click="deleteAccount" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                                    <span wire:loading.remove>{{ __('profile.content_permanently_delete_account') }}</span>
                                    <span wire:loading>{{ __('common.content_deleting') }}</span>
                                </button>
                                <button type="button" wire:click="$set('showDeleteForm', false)"
                                        class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                                    {{ __('common.action_cancel') }}
                                </button>
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        </div>

    </div>{{-- /max-w-2xl --}}
</div>
