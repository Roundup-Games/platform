<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">My Profile</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('Manage your account information, preferences, and security.') }}</p>
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
                    {{ __('Profile updated successfully.') }}
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
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('Avatar') }}</h2>

            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @php
                        $user = auth()->user();
                        $avatarMedia = $user->getFirstMedia('avatar');
                    @endphp

                    @if($avatarMedia)
                        <img src="{{ $avatarMedia->getUrl() }}"
                             alt="{{ $user->name }}"
                             class="w-20 h-20 rounded-full object-cover ring-2 ring-outline-variant/30" />
                    @else
                        <div class="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center text-primary text-2xl font-bold font-heading">
                            {{ strtoupper(Str::substr($user->name, 0, 1)) }}
                        </div>
                    @endif
                </div>

                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <label class="cursor-pointer px-4 py-2 bg-surface-container-high text-on-surface-variant rounded-lg text-sm font-medium hover:bg-surface-container transition-colors">
                            <span>{{ __('Choose Photo') }}</span>
                            <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                        </label>

                        @if($avatarMedia)
                            <button wire:click="removeAvatar" wire:loading.attr="disabled"
                                    class="text-sm text-error hover:brightness-110 transition-colors">
                                {{ __('Remove') }}
                            </button>
                        @endif
                    </div>

                    @error('avatar')
                        <p class="mt-2 text-sm text-error">{{ $message }}</p>
                    @enderror

                    @if($avatar)
                        <div class="mt-3 flex items-center gap-3">
                            <img src="{{ $avatar->temporaryUrl() }}" alt="Preview"
                                 class="w-12 h-12 rounded-full object-cover" />
                            <span class="text-xs text-on-surface-variant">{{ $avatar->getFilename() }}</span>
                        </div>
                    @endif

                    <p class="mt-2 text-xs text-on-surface-variant/70">{{ __('JPG, PNG, or GIF. Max 1MB.') }}</p>
                </div>
            </div>
        </section>

        {{-- Profile Information --}}
        <form wire:submit="saveProfile" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('Personal Information') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="profile-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('Name') }}</label>
                    <input type="text" id="profile-name" wire:model="name"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="profile-email" class="block text-sm font-medium text-on-surface mb-1">{{ __('Email') }}</label>
                    <input type="email" id="profile-email" wire:model="email"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="profile-gender" class="block text-sm font-medium text-on-surface mb-1">{{ __('Gender') }}</label>
                        <select id="profile-gender" wire:model="gender"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">{{ __('Select...') }}</option>
                            <option value="male">{{ __('Male') }}</option>
                            <option value="female">{{ __('Female') }}</option>
                            <option value="non-binary">{{ __('Non-binary') }}</option>
                            <option value="prefer-not-to-say">{{ __('Prefer not to say') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="profile-pronouns" class="block text-sm font-medium text-on-surface mb-1">{{ __('Pronouns') }}</label>
                        <select id="profile-pronouns" wire:model="pronouns"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">{{ __('Select...') }}</option>
                            <option value="he/him">{{ __('He/Him') }}</option>
                            <option value="she/her">{{ __('She/Her') }}</option>
                            <option value="they/them">{{ __('They/Them') }}</option>
                            <option value="prefer-not-to-say">{{ __('Prefer not to say') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="profile-phone" class="block text-sm font-medium text-on-surface mb-1">{{ __('Phone') }}</label>
                    <input type="tel" id="profile-phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Game Preferences --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Game Preferences') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("Select the games you enjoy and those you'd prefer to avoid — we'll use this to recommend sessions and events.") }}</p>

                {{-- Favorite Games --}}
                <div class="mb-6">
                    <h4 class="text-sm font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Favorite Games') }}</h4>
                    <p class="text-xs text-on-surface-variant mb-3">{{ __("Selecting a base game as a favorite implies you also enjoy its expansions.") }}</p>

                    <livewire:components.game-system-preference-picker
                        :wire:key="'picker-favorite'"
                        preferenceType="favorite"
                        :selectedIds="$favoriteGameSystemIds"
                        :conflictIds="$avoidedGameSystemIds"
                    />
                </div>

                {{-- Games to Avoid --}}
                <div class="mb-2">
                    <h4 class="text-sm font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Games to Avoid') }}</h4>
                    <p class="text-xs text-on-surface-variant mb-3">{{ __("Avoid preferences take priority over favorites when there's a conflict.") }}</p>

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
                    {{ __(':favorites favorite, :avoids avoided', [
                        'favorites' => count($favoriteGameSystemIds),
                        'avoids' => count($avoidedGameSystemIds),
                    ]) }}
                </p>
            </div>

            {{-- Vibe Preferences --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Vibe Preferences') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("Tell us which play styles you enjoy and which you'd rather avoid.") }}</p>

                <livewire:components.vibe-preference-picker
                    :wire:key="'vibe-prefs'"
                    :preferences="$vibePreferences"
                />

                @php
                    $vibeFavorites = count(array_filter($vibePreferences, fn ($v) => $v === 'favorite'));
                    $vibeAvoids = count(array_filter($vibePreferences, fn ($v) => $v === 'avoid'));
                @endphp
                <p class="mt-3 text-xs text-on-surface-variant">
                    {{ __(':favorites favorite, :avoids avoided', [
                        'favorites' => $vibeFavorites,
                        'avoids' => $vibeAvoids,
                    ]) }}
                </p>
            </div>

            {{-- Language & Location --}}
            <div class="pt-4 border-t border-outline-variant/30">
                <h3 class="text-base font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Language & Location') }}</h3>
                <p class="text-sm text-on-surface-variant mb-6">{{ __("Set your preferred language and location to help us find sessions near you.") }}</p>

                <div class="space-y-4">
                    <div>
                        <label for="profile-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('Preferred Language') }}</label>
                        <select id="profile-language" wire:model="preferredLanguage"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">{{ __('Select...') }}</option>
                            @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                            @endforeach
                        </select>
                        @error('preferredLanguage') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-location" class="block text-sm font-medium text-on-surface mb-1">{{ __('Location') }}</label>
                        <input type="text" id="profile-location" wire:model="locationAddress" placeholder="{{ __('City, Country') }}"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('locationAddress') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Save Button --}}
            <div class="pt-2">
                <button type="submit" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                    <span wire:loading.remove>{{ __('Save Changes') }}</span>
                    <span wire:loading>{{ __('Saving...') }}</span>
                </button>
            </div>
        </form>

        {{-- Linked Accounts --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('Linked Accounts') }}</h2>

            <div class="space-y-3">
                @forelse($linkedAccounts as $linkedAccount)
                    <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                        <div class="flex items-center gap-3">
                            @if($linkedAccount->provider === 'google')
                                <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-on-surface capitalize">{{ $linkedAccount->provider }}</p>
                                <p class="text-xs text-on-surface-variant">{{ __('Connected :date', ['date' => format_date($linkedAccount->created_at, 'date')]) }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                            {{ __('Connected') }}
                        </span>
                    </div>
                @empty
                    <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">{{ __('Google') }}</p>
                                <p class="text-xs text-on-surface-variant">{{ __('Not connected') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('oauth.redirect', 'google') }}"
                           class="inline-flex items-center px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                            {{ __('Connect') }}
                        </a>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Password Section --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('Password') }}</h2>
                @if(!$showPasswordForm)
                    <button wire:click="$set('showPasswordForm', true)"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                        {{ $userHasPassword ? __('Change Password') : __('Set Password') }}
                    </button>
                @endif
            </div>

            @if($showPasswordForm)
                <form wire:submit="changePassword" class="space-y-4">
                    @if($userHasPassword)
                        <div>
                            <label for="profile-current-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('Current Password') }}</label>
                            <input type="password" id="profile-current-password" wire:model="current_password" autocomplete="current-password"
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                            @error('current_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div class="rounded-lg bg-primary/5 border border-primary/20 p-3 mb-2">
                            <p class="text-sm text-on-surface-variant flex items-start gap-2">
                                <span class="material-symbols-outlined text-base text-primary mt-0.5" style="font-variation-settings: 'FILL' 1">info</span>
                                {{ __('Your account was created via :provider. Set a password to enable email/password login.', ['provider' => $linkedAccounts->count() > 0 ? $linkedAccounts->first()->provider : __('a third-party provider')]) }}
                            </p>
                        </div>
                    @endif

                    <div>
                        <label for="profile-new-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('New Password') }}</label>
                        <input type="password" id="profile-new-password" wire:model="password" autocomplete="new-password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-confirm-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('Confirm Password') }}</label>
                        <input type="password" id="profile-confirm-password" wire:model="password_confirmation" autocomplete="new-password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                            <span wire:loading.remove>{{ $userHasPassword ? __('Update Password') : __('Set Password') }}</span>
                            <span wire:loading>{{ $userHasPassword ? __('Updating...') : __('Setting...') }}</span>
                        </button>
                        <button type="button" wire:click="$set('showPasswordForm', false)"
                                class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                            {{ __('Cancel') }}
                        </button>
                    </div>
                </form>
            @else
                @if($userHasPassword)
                    <p class="text-sm text-on-surface-variant">{{ __('Your password is set. Click "Change Password" above to update it.') }}</p>
                @else
                    <p class="text-sm text-on-surface-variant flex items-center gap-2">
                        <span class="material-symbols-outlined text-base text-on-surface-variant">warning</span>
                        {{ __('No password set. You currently sign in via a linked provider.') }}
                    </p>
                @endif
            @endif
        </section>

        {{-- Danger Zone: Delete Account --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
            <h2 class="text-lg font-heading font-semibold text-error mb-2 tracking-tight">{{ __('Delete Account') }}</h2>
            <p class="text-sm text-on-surface-variant mb-4">
                {{ __('Once you delete your account, all of your resources and data will be permanently deleted. This action cannot be undone.') }}
            </p>

            @if(!$showDeleteForm)
                <button wire:click="$set('showDeleteForm', true)"
                        class="px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                    {{ __('Delete Account') }}
                </button>
            @else
                <div class="space-y-4 mt-4 pt-4 border-t border-error/20">
                    @if($userHasPassword)
                        <div>
                            <label for="delete-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('Confirm Your Password') }}</label>
                            <input type="password" id="delete-password" wire:model="delete_password" autocomplete="current-password"
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                            @error('delete_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="delete-confirm" class="block text-sm font-medium text-on-surface mb-1">
                                {!! __('Type :word to confirm', ['word' => '<strong class="text-error">DELETE</strong>']) !!}
                            </label>
                            <input type="text" id="delete-confirm" wire:model="delete_confirmation" autocomplete="off"
                                   class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                            @error('delete_confirmation') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex items-center gap-3">
                        <button wire:click="deleteAccount" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                            <span wire:loading.remove>{{ __('Permanently Delete Account') }}</span>
                            <span wire:loading>{{ __('Deleting...') }}</span>
                        </button>
                        <button type="button" wire:click="$set('showDeleteForm', false)"
                                class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                            {{ __('Cancel') }}
                        </button>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
