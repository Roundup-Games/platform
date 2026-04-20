<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('profile.content_my_profile') }}</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('profile.action_manage_your_account_information_preferences') }}</p>
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
        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ __('profile.flash_profile_updated_successfully') }}
                </p>
            </div>
        @endif
        @if(session('password_updated'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ session('password_updated') }}
                </p>
            </div>
        @endif

        {{-- Avatar Section --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('profile.field_avatar') }}</h2>

            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    <x-user-avatar :user="auth()->user()" size="w-20 h-20" text-size="text-2xl" />
                </div>

                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <label class="cursor-pointer px-4 py-2 bg-surface-container-high text-on-surface-variant rounded-lg text-sm font-medium hover:bg-surface-container transition-colors">
                            <span>{{ __('common.field_choose_photo') }}</span>
                            <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                        </label>

                        @php
                            $avatarMedia = auth()->user()->getFirstMedia('avatar');
                        @endphp
                        @if($avatarMedia)
                            <button wire:click="removeAvatar" wire:loading.attr="disabled"
                                    class="text-sm text-error hover:brightness-110 transition-colors">
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
                                <img src="{{ $previewUrl }}" alt="Preview"
                                     class="w-12 h-12 rounded-full object-cover" />
                            @endif
                            <span class="text-xs text-on-surface-variant">{{ $avatar->getClientOriginalName() }}</span>
                        </div>
                    @endif

                    <p class="mt-2 text-xs text-on-surface-variant/70">{{ __('common.content_jpg_png_or_gif_max_1mb') }}</p>
                </div>
            </div>
        </section>

        {{-- Profile Information --}}
        <form wire:submit="saveProfile" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('common.content_personal_information') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="profile-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_name') }}</label>
                    <input type="text" id="profile-name" wire:model="name"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="profile-email" class="block text-sm font-medium text-on-surface mb-1">{{ __('emails.field_email') }}</label>
                    <input type="email" id="profile-email" wire:model="email"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="profile-gender" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_gender') }}</label>
                        <select id="profile-gender" wire:model="gender"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
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
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
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
                    <input type="tel" id="profile-phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Game Preferences --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('games.content_game_preferences') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_select_the_games_you_enjoy_and_those") }}</p>

                {{-- Favorite Games --}}
                <div class="mb-6">
                    <h4 class="text-sm font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('games.content_favorite_games') }}</h4>
                    <p class="text-xs text-on-surface-variant mb-3">{{ __("games.content_selecting_a_base_game_as_a_favorite_implies") }}</p>

                    <livewire:components.game-system-preference-picker
                        :wire:key="'picker-favorite'"
                        preferenceType="favorite"
                        :selectedIds="$favoriteGameSystemIds"
                        :conflictIds="$avoidedGameSystemIds"
                    />
                </div>

                {{-- Games to Avoid --}}
                <div class="mb-2">
                    <h4 class="text-sm font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('games.content_games_to_avoid') }}</h4>
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

                <p class="mt-3 text-xs text-on-surface-variant">
                    {{ __('profile.content_favorites_favorite_avoids_avoided', [
                        'favorites' => count($favoriteGameSystemIds),
                        'avoids' => count($avoidedGameSystemIds),
                    ]) }}
                </p>
            </div>

            {{-- Vibe Preferences --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('profile.content_vibe_preferences') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_tell_us_which_play_styles_you_enjoy") }}</p>

                <livewire:components.vibe-preference-picker
                    :wire:key="'vibe-prefs'"
                    :preferences="$vibePreferences"
                />

                @php
                    $vibeFavorites = count(array_filter($vibePreferences, fn ($v) => $v === 'favorite'));
                    $vibeAvoids = count(array_filter($vibePreferences, fn ($v) => $v === 'avoid'));
                @endphp
                <p class="mt-3 text-xs text-on-surface-variant">
                    {{ __('profile.content_favorites_favorite_avoids_avoided', [
                        'favorites' => $vibeFavorites,
                        'avoids' => $vibeAvoids,
                    ]) }}
                </p>
            </div>

            {{-- Language & Location --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('profile.content_language_location') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_set_your_preferred_language_and_location") }}</p>

                <div class="space-y-4">
                    <div>
                        <label for="profile-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('profile.content_preferred_language') }}</label>
                        <select id="profile-language" wire:model="preferredLanguage"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">{{ __('common.content_select') }}</option>
                            @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                            @endforeach
                        </select>
                        @error('preferredLanguage') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Location Section --}}
                    <div>
                        <label class="block text-sm font-medium text-on-surface mb-1">{{ __('location.content_location') }}</label>
                        <livewire:components.location-picker :location-id="$locationId" />
                    </div>
                </div>
            </div>

            {{-- Save Button --}}
            <div class="pt-2">
                <button type="submit" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                    <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
                    <span wire:loading>{{ __('common.content_saving') }}</span>
                </button>
            </div>
        </form>

        {{-- Linked Accounts --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('profile.field_linked_accounts') }}</h2>

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
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
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
                           class="inline-flex items-center px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                            {{ __('common.action_connect') }}
                        </a>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Privacy Settings --}}
        <form wire:submit="savePrivacySettings" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 space-y-4">
            <div>
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('profile.content_privacy_settings') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('profile.action_control_who_sees_your_profile_information') }}</p>
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

            <div class="space-y-4">
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
                @endphp

                @foreach(\App\Services\ProfileVisibilityResolver::FIELDS as $field)
                    <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-lg text-on-surface-variant">{{ $fieldIcons[$field] ?? 'info' }}</span>
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
                                            'px-3 py-1.5 text-xs font-medium transition-colors',
                                            'bg-primary text-on-primary' => $isActive,
                                            'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => !$isActive,
                                        ])>
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            @error('privacySettings') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

            <div class="pt-2">
                <button type="submit" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                    <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
                    <span wire:loading>{{ __('common.content_saving') }}</span>
                </button>
            </div>
        </form>

        {{-- Password Section --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('auth.field_password') }}</h2>
                @if(!$showPasswordForm)
                    <button wire:click="$set('showPasswordForm', true)"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors">
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
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                            @error('current_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div class="rounded-lg bg-primary/5 border border-primary/20 p-3 mb-2">
                            <p class="text-sm text-on-surface-variant flex items-start gap-2">
                                <span class="material-symbols-outlined text-base text-primary mt-0.5" style="font-variation-settings: 'FILL' 1">info</span>
                                {{ __('emails.content_your_account_was_created_via', ['provider' => $linkedAccounts->count() > 0 ? $linkedAccounts->first()->provider : __('common.content_a_third_party_provider')]) }}
                            </p>
                        </div>
                    @endif

                    <div>
                        <label for="profile-new-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_new_password') }}</label>
                        <input type="password" id="profile-new-password" wire:model="password" autocomplete="new-password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-confirm-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_password') }}</label>
                        <input type="password" id="profile-confirm-password" wire:model="password_confirmation" autocomplete="new-password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                            <span wire:loading.remove>{{ $userHasPassword ? __('auth.field_update_password') : __('auth.field_set_password') }}</span>
                            <span wire:loading>{{ $userHasPassword ? __('common.content_updating') : __('profile.content_setting') }}</span>
                        </button>
                        <button type="button" wire:click="$set('showPasswordForm', false)"
                                class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                            {{ __('common.action_cancel') }}
                        </button>
                    </div>
                </form>
            @else
                @if($userHasPassword)
                    <p class="text-sm text-on-surface-variant">{{ __('auth.content_your_password_is_set_click') }}</p>
                @else
                    <p class="text-sm text-on-surface-variant flex items-center gap-2">
                        <span class="material-symbols-outlined text-base text-on-surface-variant">warning</span>
                        {{ __('auth.content_no_password_set_you_currently') }}
                    </p>
                @endif
            @endif
        </section>

        {{-- Danger Zone: Delete Account --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
            <h2 class="text-lg font-heading font-semibold text-error mb-2 tracking-tight">{{ __('profile.action_delete_account') }}</h2>
            <p class="text-sm text-on-surface-variant mb-4">
                {{ __('profile.error_once_you_delete_your_account') }}
            </p>

            @if(!$showDeleteForm)
                <button wire:click="$set('showDeleteForm', true)"
                        class="px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                    {{ __('profile.action_delete_account') }}
                </button>
            @else
                <div class="space-y-4 mt-4 pt-4 border-t border-error/20">
                    @if($userHasPassword)
                        <div>
                            <label for="delete-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_your_password') }}</label>
                            <input type="password" id="delete-password" wire:model="delete_password" autocomplete="current-password"
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                            @error('delete_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="delete-confirm" class="block text-sm font-medium text-on-surface mb-1">
                                {!! __('discovery.content_type_word_to_confirm', ['word' => '<strong class="text-error">DELETE</strong>']) !!}
                            </label>
                            <input type="text" id="delete-confirm" wire:model="delete_confirmation" autocomplete="off"
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                            @error('delete_confirmation') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex items-center gap-3">
                        <button wire:click="deleteAccount" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                            <span wire:loading.remove>{{ __('profile.content_permanently_delete_account') }}</span>
                            <span wire:loading>{{ __('common.content_deleting') }}</span>
                        </button>
                        <button type="button" wire:click="$set('showDeleteForm', false)"
                                class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                            {{ __('common.action_cancel') }}
                        </button>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
