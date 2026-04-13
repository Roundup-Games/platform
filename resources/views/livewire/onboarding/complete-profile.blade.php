<div>
    <!-- Progress indicator -->
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            @foreach(['Identity', 'Contact', 'Preferences'] as $i => $label)
                <div class="flex items-center {{ $loop->last ? '' : 'flex-1' }}">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium
                        {{ $step > $i + 1 ? 'bg-primary text-on-primary' : ($step === $i + 1 ? 'bg-primary text-on-primary' : 'bg-surface-container-highest dark:bg-[#3a3b34] text-on-surface-variant') }}">
                        @if($step > $i + 1)
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check</span>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </div>
                    <span class="ml-2 text-xs font-medium {{ $step === $i + 1 ? 'text-on-surface dark:text-[#eae8e0] font-semibold' : 'text-on-surface-variant' }} hidden sm:inline">
                        {{ $label }}
                    </span>
                    @if(!$loop->last)
                        <div class="flex-1 mx-3 h-0.5 {{ $step > $i + 1 ? 'bg-primary' : 'bg-outline-variant/30' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="bg-surface-container-lowest dark:bg-[#2a2b24] rounded-2xl shadow-ambient p-6 sm:p-8 border border-outline-variant/10">
        <!-- Step 1: Identity -->
        @if($step === 1)
            <h2 class="text-xl font-heading font-semibold text-on-surface dark:text-[#eae8e0] mb-1">
                Tell us about yourself
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                This helps us personalize your experience.
            </p>

            <div class="space-y-4">
                <div>
                    <label for="gender" class="block text-sm font-medium text-on-surface dark:text-[#eae8e0] mb-1">
                        Gender <span class="text-error">*</span>
                    </label>
                    <select id="gender" wire:model="gender"
                            class="w-full rounded-md border-outline-variant/30 dark:bg-[#1b1c17] dark:text-[#eae8e0] shadow-sm focus:border-primary focus:ring-primary">
                        <option value="">Select...</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="non-binary">Non-binary</option>
                        <option value="prefer-not-to-say">Prefer not to say</option>
                        <option value="other">Other</option>
                    </select>
                    @error('gender') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="pronouns" class="block text-sm font-medium text-on-surface dark:text-[#eae8e0] mb-1">
                        Pronouns <span class="text-error">*</span>
                    </label>
                    <select id="pronouns" wire:model="pronouns"
                            class="w-full rounded-md border-outline-variant/30 dark:bg-[#1b1c17] dark:text-[#eae8e0] shadow-sm focus:border-primary focus:ring-primary">
                        <option value="">Select...</option>
                        <option value="he/him">He/Him</option>
                        <option value="she/her">She/Her</option>
                        <option value="they/them">They/Them</option>
                        <option value="prefer-not-to-say">Prefer not to say</option>
                        <option value="other">Other</option>
                    </select>
                    @error('pronouns') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <!-- Step 2: Contact -->
        @if($step === 2)
            <h2 class="text-xl font-heading font-semibold text-on-surface dark:text-[#eae8e0] mb-1">
                Contact information
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                Optional — useful for game night coordination.
            </p>

            <div>
                <label for="phone" class="block text-sm font-medium text-on-surface dark:text-[#eae8e0] mb-1">
                    Phone number <span class="text-on-surface-variant">(optional)</span>
                </label>
                <input type="tel" id="phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                       class="w-full rounded-md border-outline-variant/30 dark:bg-[#1b1c17] dark:text-[#eae8e0] shadow-sm focus:border-primary focus:ring-primary" />
                @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <!-- Step 3: Game Preferences -->
        @if($step === 3)
            <h2 class="text-xl font-heading font-semibold text-on-surface dark:text-[#eae8e0] mb-1">
                Game preferences
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                Select the games you enjoy — we'll use this to recommend sessions and events.
            </p>

            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                @foreach($gameSystems as $gameSystem)
                    <label class="flex items-center space-x-3 p-3 rounded-xl hover:bg-surface-container-low dark:hover:bg-[#3a3b34] cursor-pointer transition-colors">
                        <input type="checkbox"
                               value="{{ $gameSystem->id }}"
                               wire:model="favoriteGameSystemIds"
                               class="rounded border-outline-variant text-primary focus:ring-primary dark:bg-[#1b1c17] dark:border-outline-variant" />
                        <div>
                            <span class="text-sm font-medium text-on-surface dark:text-[#eae8e0]">{{ $gameSystem->name }}</span>
                            @if($gameSystem->description)
                                <p class="text-xs text-on-surface-variant line-clamp-1">{{ Str::limit($gameSystem->description, 80) }}</p>
                            @endif
                        </div>
                    </label>
                @endforeach

                @if($gameSystems->isEmpty())
                    <p class="text-sm text-on-surface-variant italic py-4 text-center">
                        No game systems available yet — you can add preferences later from your profile.
                    </p>
                @endif
            </div>

            <p class="mt-4 text-xs text-on-surface-variant">
                {{ count($this->favoriteGameSystemIds) }} selected
            </p>
        @endif

        <!-- Navigation -->
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-outline-variant/15">
            @if($step > 1)
                <button wire:click="previousStep"
                        class="inline-flex items-center text-sm text-on-surface-variant hover:text-primary dark:hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-base mr-1">arrow_back</span>
                    Back
                </button>
            @else
                <span></span>
            @endif

            @if($step < 3)
                <button wire:click="nextStep"
                        class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                    Continue
                </button>
            @else
                <div class="flex items-center gap-3">
                    <button wire:click="complete" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                        <span wire:loading.remove>Complete Profile</span>
                        <span wire:loading>Saving...</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
