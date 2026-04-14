<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">My Profile</h1>
            <p class="mt-1 text-sm text-on-surface-variant">Manage your account information and preferences.</p>
        </div>

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
                    Profile updated successfully.
                </p>
            </div>
        @endif

        @if(session('password_updated'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ session('password_updated') }}
                </p>
            </div>
        @endif

        {{-- Avatar Section --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">Avatar</h2>

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
                            <span>Choose Photo</span>
                            <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                        </label>

                        @if($avatarMedia)
                            <button wire:click="removeAvatar" wire:loading.attr="disabled"
                                    class="text-sm text-error hover:brightness-110 transition-colors">
                                Remove
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

                    <p class="mt-2 text-xs text-on-surface-variant/70">JPG, PNG, or GIF. Max 1MB.</p>
                </div>
            </div>
        </section>

        {{-- Profile Information --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">Profile Information</h2>
                <a href="{{ route('profile.edit-form') }}" wire:navigate
                   class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                    Edit
                </a>
            </div>

            <div class="space-y-4">
                <div>
                    <label for="profile-name" class="block text-sm font-medium text-on-surface mb-1">Name</label>
                    <input type="text" id="profile-name" wire:model="name"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="profile-email" class="block text-sm font-medium text-on-surface mb-1">Email</label>
                    <input type="email" id="profile-email" wire:model="email"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="profile-gender" class="block text-sm font-medium text-on-surface mb-1">Gender</label>
                        <select id="profile-gender" wire:model="gender"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">Select...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="non-binary">Non-binary</option>
                            <option value="prefer-not-to-say">Prefer not to say</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="profile-pronouns" class="block text-sm font-medium text-on-surface mb-1">Pronouns</label>
                        <select id="profile-pronouns" wire:model="pronouns"
                                class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                            <option value="">Select...</option>
                            <option value="he/him">He/Him</option>
                            <option value="she/her">She/Her</option>
                            <option value="they/them">They/Them</option>
                            <option value="prefer-not-to-say">Prefer not to say</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="profile-phone" class="block text-sm font-medium text-on-surface mb-1">Phone</label>
                    <input type="tel" id="profile-phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                </div>

                <div class="pt-2">
                    <button wire:click="saveProfile" wire:loading.attr="disabled"
                            class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                        <span wire:loading.remove>Save Changes</span>
                        <span wire:loading>Saving...</span>
                    </button>
                </div>
            </div>
        </section>

        {{-- Linked Accounts --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">Linked Accounts</h2>

            <div class="space-y-3">
                @forelse($linkedAccounts as $linkedAccount)
                    <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                        <div class="flex items-center gap-3">
                            @if($linkedAccount->provider === 'google')
                                <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-on-surface capitalize">{{ $linkedAccount->provider }}</p>
                                <p class="text-xs text-on-surface-variant">Connected {{ $linkedAccount->created_at->format('M j, Y') }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                            Connected
                        </span>
                    </div>
                @empty
                    <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                            <div>
                                <p class="text-sm font-medium text-on-surface">Google</p>
                                <p class="text-xs text-on-surface-variant">Not connected</p>
                            </div>
                        </div>
                        <a href="{{ route('oauth.redirect', 'google') }}" wire:navigate
                           class="inline-flex items-center px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                            Connect
                        </a>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Game Preferences --}}
        @if($gameSystemPreferences->isNotEmpty())
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">Game Preferences</h2>
                    <a href="{{ route('profile.edit-form') }}" wire:navigate
                       class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                        Edit
                    </a>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($gameSystemPreferences as $system)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            {{ $system->name }}
                        </span>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Password Change --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">Password</h2>
                @if(!$showPasswordForm)
                    <button wire:click="$set('showPasswordForm', true)"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                        Change Password
                    </button>
                @endif
            </div>

            @if($showPasswordForm)
                <div class="space-y-4">
                    <div>
                        <label for="profile-current-password" class="block text-sm font-medium text-on-surface mb-1">Current Password</label>
                        <input type="password" id="profile-current-password" wire:model="current_password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        @error('current_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-new-password" class="block text-sm font-medium text-on-surface mb-1">New Password</label>
                        <input type="password" id="profile-new-password" wire:model="password"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-confirm-password" class="block text-sm font-medium text-on-surface mb-1">Confirm Password</label>
                        <input type="password" id="profile-confirm-password" wire:model="password_confirmation"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="changePassword" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                            <span wire:loading.remove>Update Password</span>
                            <span wire:loading>Updating...</span>
                        </button>
                        <button wire:click="$set('showPasswordForm', false)"
                                class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-on-surface-variant">Your password is set. Click "Change Password" above to update it.</p>
            @endif
        </section>

        {{-- Danger Zone --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
            <h2 class="text-lg font-heading font-semibold text-error mb-2 tracking-tight">Delete Account</h2>
            <p class="text-sm text-on-surface-variant mb-4">
                Once you delete your account, all of your resources and data will be permanently deleted.
            </p>
            <form method="POST" action="{{ route('profile.destroy') }}">
                @csrf
                @method('DELETE')
                <div class="flex items-center gap-3">
                    <input type="password" name="password" placeholder="Confirm password" aria-label="Confirm password for account deletion"
                           class="rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-sm max-w-xs text-on-surface placeholder:text-on-surface-variant" />
                    <x-danger-button>
                        Delete Account
                    </x-danger-button>
                </div>
                @error('password', 'userDeletion')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </form>
        </section>
    </div>
</div>
