<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('Create Campaign') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('Start a recurring campaign — sessions will be created from your schedule.') }}</p>
        </div>

        {{-- Campaign Details --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Campaign Details') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="campaign-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('Campaign Name *') }}</label>
                    <input type="text" id="campaign-name" wire:model="name" placeholder="e.g. Shadows of Waterdeep"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-system" class="block text-sm font-medium text-on-surface mb-1">{{ __('Game System') }}</label>
                    <select id="campaign-system" wire:model="game_system_id"
                            class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                        <option value="">{{ __('— Select a game system —') }}</option>
                        @foreach($gameSystems as $system)
                            <option value="{{ $system->id }}">{{ $system->name }}</option>
                        @endforeach
                    </select>
                    @error('game_system_id') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('Description') }}</label>
                    <textarea id="campaign-description" wire:model="description" rows="4" placeholder="Describe the campaign setting, tone, and what to expect..."
                              class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                    @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Schedule --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Schedule') }}</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-recurrence" class="block text-sm font-medium text-on-surface mb-1">{{ __('Recurrence *') }}</label>
                        <select id="campaign-recurrence" wire:model="recurrence"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            <option value="weekly">{{ __('Weekly') }}</option>
                            <option value="bi-weekly">{{ __('Every 2 weeks') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
                            <option value="custom">{{ __('Custom') }}</option>
                        </select>
                        @error('recurrence') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('Time of Day *') }}</label>
                        <input type="time" id="campaign-time" wire:model="time_of_day"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('time_of_day') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('Session Duration (hours)') }}</label>
                        <input type="number" id="campaign-duration" wire:model="session_duration" step="0.5" min="0.5" max="24" placeholder="e.g. 3"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('session_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('Price per Session ($)') }}</label>
                        <input type="number" id="campaign-price" wire:model="price_per_session" step="0.01" min="0" placeholder="0.00"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('price_per_session') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Location & Visibility --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Location & Visibility') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="campaign-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('Visibility *') }}</label>
                    <select id="campaign-visibility" wire:model="visibility"
                            class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                        <option value="public">{{ __('Public — anyone can find and join') }}</option>
                        <option value="protected">{{ __('Protected — only with link') }}</option>
                        <option value="private">{{ __('Private — invite only') }}</option>
                    </select>
                    @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-location-details" class="block text-sm font-medium text-on-surface mb-1">{{ __('Location') }}</label>
                    <input type="text" id="campaign-location-details" wire:model="location_details" placeholder="Venue name, address, or meeting details"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('location_details') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="campaign-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('Language') }}</label>
                    <input type="text" id="campaign-language" wire:model="language" placeholder="en"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium shadow-ambient">
                <span wire:loading.remove>{{ __('Create Campaign') }}</span>
                <span wire:loading>{{ __('Creating...') }}</span>
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('Cancel') }}
            </a>
        </div>
    </div>
</div>
