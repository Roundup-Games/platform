<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        <!-- Page Header -->
        <div>
            <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">My Profile</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Manage your account information and preferences.</p>
        </div>

        @if(session()->has('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('success') }}</p>
            </div>
        @endif
        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">Profile updated successfully.</p>
            </div>
        @endif

        @if(session('password_updated'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('password_updated') }}</p>
            </div>
        @endif

        <!-- Avatar Section -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Avatar</h2>

            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @php
                        $user = auth()->user();
                        $avatarMedia = $user->getFirstMedia('avatar');
                    @endphp

                    @if($avatarMedia)
                        <img src="{{ $avatarMedia->getUrl() }}"
                             alt="{{ $user->name }}"
                             class="w-20 h-20 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-600" />
                    @else
                        <div class="w-20 h-20 rounded-full bg-[#C12E26] flex items-center justify-center text-white text-2xl font-bold font-['Montserrat']">
                            {{ strtoupper(Str::substr($user->name, 0, 1)) }}
                        </div>
                    @endif
                </div>

                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <label class="cursor-pointer px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                            <span>Choose Photo</span>
                            <input type="file" wire:model="avatar" accept="image/*" class="hidden" />
                        </label>

                        @if($avatarMedia)
                            <button wire:click="removeAvatar" wire:loading.attr="disabled"
                                    class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 transition-colors">
                                Remove
                            </button>
                        @endif
                    </div>

                    @error('avatar')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @if($avatar)
                        <div class="mt-3 flex items-center gap-3">
                            <img src="{{ $avatar->temporaryUrl() }}" alt="Preview"
                                 class="w-12 h-12 rounded-full object-cover" />
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $avatar->getFilename() }}</span>
                        </div>
                    @endif

                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">JPG, PNG, or GIF. Max 1MB.</p>
                </div>
            </div>
        </section>

        <!-- Profile Information -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Profile Information</h2>
                <a href="{{ route('profile.edit-form') }}"
                   class="text-sm text-[#C12E26] hover:text-[#9A231F] transition-colors">
                    Edit
                </a>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input type="text" wire:model="name"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" wire:model="email"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
                        <select wire:model="gender"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                            <option value="">Select...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="non-binary">Non-binary</option>
                            <option value="prefer-not-to-say">Prefer not to say</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Pronouns</label>
                        <select wire:model="pronouns"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                    <input type="tel" wire:model="phone" placeholder="+1 (555) 000-0000"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                </div>

                <div class="pt-2">
                    <button wire:click="saveProfile" wire:loading.attr="disabled"
                            class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        <span wire:loading.remove>Save Changes</span>
                        <span wire:loading>Saving...</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Linked Accounts -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Linked Accounts</h2>

            <div class="space-y-3">
                @forelse($linkedAccounts as $linkedAccount)
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <div class="flex items-center gap-3">
                            @if($linkedAccount->provider === 'google')
                                <svg class="w-5 h-5" viewBox="0 0 24 24">
                                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                </svg>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 capitalize">{{ $linkedAccount->provider }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Connected {{ $linkedAccount->created_at->format('M j, Y') }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                            Connected
                        </span>
                    </div>
                @empty
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5" viewBox="0 0 24 24">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Google</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Not connected</p>
                            </div>
                        </div>
                        <a href="{{ route('oauth.redirect', 'google') }}"
                           class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-md text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Connect
                        </a>
                    </div>
                @endforelse
            </div>
        </section>

        <!-- Game Preferences -->
        @if($gameSystemPreferences->isNotEmpty())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Game Preferences</h2>
                    <a href="{{ route('profile.edit-form') }}"
                       class="text-sm text-[#C12E26] hover:text-[#9A231F] transition-colors">
                        Edit
                    </a>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($gameSystemPreferences as $system)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#C12E26]/10 text-[#C12E26] dark:bg-[#C12E26]/20 dark:text-[#E8584F]">
                            {{ $system->name }}
                        </span>
                    @endforeach
                </div>
            </section>
        @endif

        <!-- Password Change -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 font-['Montserrat']">Password</h2>
                @if(!$showPasswordForm)
                    <button wire:click="$set('showPasswordForm', true)"
                            class="text-sm text-[#C12E26] hover:text-[#9A231F] transition-colors">
                        Change Password
                    </button>
                @endif
            </div>

            @if($showPasswordForm)
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Current Password</label>
                        <input type="password" wire:model="current_password"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('current_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Password</label>
                        <input type="password" wire:model="password"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm Password</label>
                        <input type="password" wire:model="password_confirmation"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="changePassword" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                            <span wire:loading.remove>Update Password</span>
                            <span wire:loading>Updating...</span>
                        </button>
                        <button wire:click="$set('showPasswordForm', false)"
                                class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Your password is set. Click "Change Password" above to update it.</p>
            @endif
        </section>

        <!-- Danger Zone -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 border border-red-200 dark:border-red-900/30">
            <h2 class="text-lg font-medium text-red-700 dark:text-red-400 mb-2 font-['Montserrat']">Delete Account</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Once you delete your account, all of your resources and data will be permanently deleted.
            </p>
            <form method="POST" action="{{ route('profile.destroy') }}">
                @csrf
                @method('DELETE')
                <div class="flex items-center gap-3">
                    <input type="password" name="password" placeholder="Confirm password"
                           class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm max-w-xs" />
                    <x-danger-button>
                        Delete Account
                    </x-danger-button>
                </div>
                @error('password', 'userDeletion')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </form>
        </section>
    </div>
</div>
