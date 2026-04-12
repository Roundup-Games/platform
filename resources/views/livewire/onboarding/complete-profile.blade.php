<div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 dark:bg-gray-900 px-4">
    <div class="w-full max-w-lg">
        <!-- Progress indicator -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Step {{ $step }} of 3</span>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ ['Profile', 'Contact', 'Preferences'][$step - 1] }}</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                <div class="bg-[#C12E26] h-2 rounded-full transition-all duration-300"
                     style="width: {{ ($step / 3) * 100 }}%"></div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <!-- Step 1: Identity -->
            @if($step === 1)
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6 font-['Montserrat']">
                    Tell us about yourself
                </h2>

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
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">
                    Contact information
                </h2>

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
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">
                    Game preferences
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                    Select the games you enjoy — we'll use this to recommend sessions and events.
                </p>

                <div class="space-y-2 max-h-64 overflow-y-auto">
                    @foreach($gameSystems as $gameSystem)
                        <label class="flex items-center space-x-3 p-2 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                            <input type="checkbox"
                                   value="{{ $gameSystem->id }}"
                                   wire:model="favoriteGameSystemIds"
                                   class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26] dark:border-gray-600 dark:bg-gray-700" />
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $gameSystem->name }}</span>
                        </label>
                    @endforeach

                    @if($gameSystems->isEmpty())
                        <p class="text-sm text-gray-400 italic">No game systems available yet — you can add preferences later.</p>
                    @endif
                </div>
            @endif

            <!-- Navigation -->
            <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                @if($step > 1)
                    <button wire:click="previousStep"
                            class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                        &larr; Back
                    </button>
                @else
                    <span></span>
                @endif

                @if($step < 3)
                    <button wire:click="nextStep"
                            class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        Continue
                    </button>
                @else
                    <button wire:click="complete"
                            class="px-4 py-2 bg-[#C12E26] text-white rounded-md hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        Complete Profile
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
