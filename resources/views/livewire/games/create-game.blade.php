<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('Create Game Session') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('Schedule a new game session for players to join.') }}</p>
        </div>

        {{-- Game Details --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Game Details') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="game-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('Session Name *') }}</label>
                    <input type="text" id="game-name" wire:model="name" placeholder="e.g. Dungeon Crawl Night"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <livewire:components.game-system-picker
                        :fieldId="'game-system'"
                        :label="__('Game System')"
                        :error="$errors->first('game_system_id')"
                        wire:model="game_system_id"
                    />
                </div>

                <div>
                    <label for="game-date-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('Date & Time *') }}</label>
                    <input type="datetime-local" id="game-date-time" wire:model="date_time"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('date_time') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="game-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('Description') }}</label>
                    <textarea id="game-description" wire:model="description" rows="3" placeholder="Describe the session..."
                              class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                    @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="game-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('Duration (hours)') }}</label>
                        <input type="number" id="game-duration" wire:model="expected_duration" step="0.5" min="0.5" max="24" placeholder="e.g. 3"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('expected_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="game-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('Price ($)') }}</label>
                        <input type="number" id="game-price" wire:model="price" step="0.01" min="0" placeholder="0.00"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('price') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="game-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('Language') }}</label>
                        <input type="text" id="game-language" wire:model="language" placeholder="en"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="game-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('Visibility *') }}</label>
                        <select id="game-visibility" wire:model="visibility"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            <option value="public">{{ __('Public — anyone can find and join') }}</option>
                            <option value="protected">{{ __('Protected — only with link') }}</option>
                            <option value="private">{{ __('Private — invite only') }}</option>
                        </select>
                        @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Location --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('Location') }}</h2>

            <div>
                <label for="game-location-details" class="block text-sm font-medium text-on-surface mb-1">{{ __('Location') }}</label>
                <input type="text" id="game-location-details" wire:model="location_details" placeholder="Venue name, address, or meeting details"
                       class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                @error('location_details') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium shadow-ambient">
                <span wire:loading.remove>{{ __('Create Game') }}</span>
                <span wire:loading>{{ __('Creating...') }}</span>
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('Cancel') }}
            </a>
        </div>
    </div>
</div>
