<div>
    <!-- Progress indicator -->
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            @foreach(['Identity', 'Contact', 'Preferences'] as $i => $label)
                <div class="flex items-center {{ $loop->last ? '' : 'flex-1' }}">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium
                        {{ $step > $i + 1 ? 'bg-[#C12E26] text-white' : ($step === $i + 1 ? 'bg-[#C12E26] text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400') }}">
                        @if($step > $i + 1)
                            ✓
                        @else
                            {{ $i + 1 }}
                        @endif
                    </div>
                    <span class="ml-2 text-xs font-medium {{ $step === $i + 1 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500' }} hidden sm:inline">
                        {{ $label }}
                    </span>
                    @if(!$loop->last)
                        <div class="flex-1 mx-3 h-0.5 {{ $step > $i + 1 ? 'bg-[#C12E26]' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 sm:p-8">
        <!-- Step 1: Identity -->
        @if($step === 1)
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-1 font-['Montserrat']">
                Tell us about yourself
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                This helps us personalize your experience.
            </p>

            <div class="space-y-4">
                <div>
                    <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Gender <span class="text-red-500">*</span>
                    </label>
                    <select id="gender" wire:model="gender"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                        <option value="">Select...</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="non-binary">Non-binary</option>
                        <option value="prefer-not-to-say">Prefer not to say</option>
                        <option value="other">Other</option>
                    </select>
                    @error('gender') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="pronouns" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Pronouns <span class="text-red-500">*</span>
                    </label>
                    <select id="pronouns" wire:model="pronouns"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                        <option value="">Select...</option>
                        <option value="he/him">He/Him</option>
                        <option value="she/her">She/Her</option>
                        <option value="they/them">They/Them</option>
                        <option value="prefer-not-to-say">Prefer not to say</option>
                        <option value="other">Other</option>
                    </select>
                    @error('pronouns') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <!-- Step 2: Contact -->
        @if($step === 2)
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-1">
                Contact information
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                Optional — useful for game night coordination.
            </p>

            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Phone number <span class="text-gray-400">(optional)</span>
                </label>
                <input type="tel" id="phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <!-- Step 3: Game Preferences -->
        @if($step === 3)
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-1">
                Game preferences
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                Select the games you enjoy — we'll use this to recommend sessions and events.
            </p>

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
                        No game systems available yet — you can add preferences later from your profile.
                    </p>
                @endif
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                {{ count($this->favoriteGameSystemIds) }} selected
            </p>
        @endif

        <!-- Navigation -->
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            @if($step > 1)
                <button wire:click="previousStep"
                        class="inline-flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                    <svg aria-hidden="true" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Back
                </button>
            @else
                <span></span>
            @endif

            @if($step < 3)
                <button wire:click="nextStep"
                        class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                    Continue
                </button>
            @else
                <div class="flex items-center gap-3">
                    <button wire:click="complete" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        <span wire:loading.remove>Complete Profile</span>
                        <span wire:loading>Saving...</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
