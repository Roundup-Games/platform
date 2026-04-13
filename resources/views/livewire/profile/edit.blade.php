<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        <!-- Page Header -->
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('profile.show') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Edit Profile</h1>
            </div>
            <p class="ml-8 text-sm text-gray-500 dark:text-gray-400">Update your profile information and game preferences.</p>
        </div>

        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">Profile updated successfully.</p>
            </div>
        @endif

        <!-- Profile Fields -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Personal Information</h2>

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
                    @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <!-- Game Preferences -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1 font-['Montserrat']">Game Preferences</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Select the games you enjoy — we'll use this to recommend sessions and events.</p>

            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                @foreach($gameSystems as $gameSystem)
                    <label class="flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors">
                        <input type="checkbox"
                               value="{{ $gameSystem->id }}"
                               wire:model="favoriteGameSystemIds"
                               class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26] dark:border-gray-600 dark:bg-gray-700" />
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $gameSystem->name }}</span>
                            @if($gameSystem->description)
                                <p class="text-xs text-gray-400 dark:text-gray-500 line-clamp-1">{{ Str::limit($gameSystem->description, 80) }}</p>
                            @endif
                        </div>
                    </label>
                @endforeach

                @if($gameSystems->isEmpty())
                    <p class="text-sm text-gray-400 italic py-4 text-center">
                        No game systems available yet.
                    </p>
                @endif
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                {{ count($this->favoriteGameSystemIds) }} selected
            </p>
        </section>

        <!-- Save Button -->
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                <span wire:loading.remove>Save Changes</span>
                <span wire:loading>Saving...</span>
            </button>
            <a href="{{ route('profile.show') }}"
               class="px-4 py-2.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                Cancel
            </a>
        </div>
    </div>
</div>
