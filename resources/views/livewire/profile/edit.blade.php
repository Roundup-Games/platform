<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('profile.show') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('Edit Profile') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('Update your profile information and game preferences.') }}</p>
        </div>

        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ __('Profile updated successfully.') }}
                </p>
            </div>
        @endif

        {{-- Personal Information --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('Personal Information') }}</h2>

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
        </section>

        {{-- Game Preferences --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-1">{{ __('Game Preferences') }}</h2>
            <p class="text-sm text-on-surface-variant mb-4">{{ __("Select the games you enjoy — we'll use this to recommend sessions and events.") }}</p>

            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                @foreach($gameSystems as $gameSystem)
                    <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-surface-container-low cursor-pointer transition-colors">
                        <input type="checkbox"
                               value="{{ $gameSystem->id }}"
                               wire:model="favoriteGameSystemIds"
                               class="rounded border-outline text-primary focus:ring-primary/20" />
                        <div>
                            <span class="text-sm font-medium text-on-surface">{{ $gameSystem->name }}</span>
                            @if($gameSystem->description)
                                <p class="text-xs text-on-surface-variant line-clamp-1">{{ Str::limit($gameSystem->description, 80) }}</p>
                            @endif
                        </div>
                    </label>
                @endforeach

                @if($gameSystems->isEmpty())
                    <p class="text-sm text-on-surface-variant italic py-4 text-center">
                        {{ __('No game systems available yet.') }}
                    </p>
                @endif
            </div>

            <p class="mt-4 text-xs text-on-surface-variant">
                {{ __(':count selected', ['count' => count($this->favoriteGameSystemIds)]) }}
            </p>
        </section>

        {{-- Save Button --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                <span wire:loading.remove>{{ __('Save Changes') }}</span>
                <span wire:loading>{{ __('Saving...') }}</span>
            </button>
            <a href="{{ route('profile.show') }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('Cancel') }}
            </a>
        </div>
    </div>
</div>
