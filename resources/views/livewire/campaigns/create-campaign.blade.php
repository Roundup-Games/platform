<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Create Campaign</h1>
            </div>
            <p class="ml-8 text-sm text-gray-500 dark:text-gray-400">Start a recurring campaign — sessions will be created from your schedule.</p>
        </div>

        {{-- Form --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Campaign Details</h2>

            <div class="space-y-4">
                <div>
                    <label for="campaign-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Campaign Name *</label>
                    <input type="text" id="campaign-name" wire:model="name" placeholder="e.g. Shadows of Waterdeep"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-system" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Game System</label>
                    <select id="campaign-system" wire:model="game_system_id"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                        <option value="">— Select a game system —</option>
                        @foreach($gameSystems as $system)
                            <option value="{{ $system->id }}">{{ $system->name }}</option>
                        @endforeach
                    </select>
                    @error('game_system_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea id="campaign-description" wire:model="description" rows="4" placeholder="Describe the campaign setting, tone, and what to expect..."
                              class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                    @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Schedule --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Schedule</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-recurrence" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Recurrence *</label>
                        <select id="campaign-recurrence" wire:model="recurrence"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Every 2 weeks</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom">Custom</option>
                        </select>
                        @error('recurrence') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Time of Day *</label>
                        <input type="time" id="campaign-time" wire:model="time_of_day"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('time_of_day') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Session Duration (hours)</label>
                        <input type="number" id="campaign-duration" wire:model="session_duration" step="0.5" min="0.5" max="24" placeholder="e.g. 3"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('session_duration') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price per Session ($)</label>
                        <input type="number" id="campaign-price" wire:model="price_per_session" step="0.01" min="0" placeholder="0.00"
                               class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                        @error('price_per_session') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Location & Visibility --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4 font-['Montserrat']">Location & Visibility</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-location-type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location Type</label>
                        <select id="campaign-location-type" wire:model="location_type"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                            <option value="online">Online (Virtual Tabletop)</option>
                            <option value="offline">In-Person</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </div>

                    <div>
                        <label for="campaign-visibility" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Visibility *</label>
                        <select id="campaign-visibility" wire:model="visibility"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                            <option value="public">Public — anyone can find and join</option>
                            <option value="protected">Protected — only with link</option>
                            <option value="private">Private — invite only</option>
                        </select>
                        @error('visibility') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="campaign-location-details" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location Details</label>
                    <input type="text" id="campaign-location-details" wire:model="location_details" placeholder="VTT link, address, or meeting details"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('location_details') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-language" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Language</label>
                    <input type="text" id="campaign-language" wire:model="language" placeholder="en"
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('language') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                <span wire:loading.remove>Create Campaign</span>
                <span wire:loading>Creating...</span>
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="px-4 py-2.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                Cancel
            </a>
        </div>
    </div>
</div>
