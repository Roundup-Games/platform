<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        <!-- Page Header -->
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Profile</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Manage your account information and preferences.</p>
        </div>

        @if($saved)
            <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">Profile updated successfully.</p>
            </div>
        @endif

        @if(session('password_updated'))
            <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('password_updated') }}</p>
            </div>
        @endif

        <!-- Profile Information -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Profile Information</h2>

            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input type="text" id="name" wire:model="name"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" id="email" wire:model="email"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
                        <select id="gender" wire:model="gender"
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
                        <label for="pronouns" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Pronouns</label>
                        <select id="pronouns" wire:model="pronouns"
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
                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                    <input type="tel" id="phone" wire:model="phone"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                </div>

                <div class="pt-2">
                    <button wire:click="saveProfile"
                            class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        Save Changes
                    </button>
                </div>
            </div>
        </section>

        <!-- Password Change -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Password</h2>
                @if(!$showPasswordForm)
                    <button wire:click="$set('showPasswordForm', true)"
                            class="text-sm text-[#C12E26] hover:text-[#9A231F]">
                        Change Password
                    </button>
                @endif
            </div>

            @if($showPasswordForm)
                <div class="space-y-4">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Current Password</label>
                        <input type="password" id="current_password" wire:model="current_password"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('current_password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Password</label>
                        <input type="password" id="password" wire:model="password"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm Password</label>
                        <input type="password" id="password_confirmation" wire:model="password_confirmation"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="changePassword"
                                class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                            Update Password
                        </button>
                        <button wire:click="$set('showPasswordForm', false)"
                                class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm">
                            Cancel
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Your password is set. Click "Change Password" above to update it.</p>
            @endif
        </section>
    </div>
</div>
