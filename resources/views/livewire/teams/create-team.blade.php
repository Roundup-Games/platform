<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('teams.browse') }}" wire:navigate class="text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.action_create_team') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('teams.action_start_a_new_team_you_ll_be_the_captain') }}</p>
        </div>

        {{-- Form --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading tracking-tight">{{ __('teams.content_team_information') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="team-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('teams.field_team_name') }}</label>
                    <input type="text" id="team-name" wire:model="name" placeholder="e.g. Roundup Ravens"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="team-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_description') }}</label>
                    <textarea id="team-description" wire:model="description" rows="3" placeholder="A short description of your team..."
                              class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant"></textarea>
                    @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="team-city" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_city') }}</label>
                        <input type="text" id="team-city" wire:model="city" placeholder="e.g. Austin"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-country" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_country') }}</label>
                        <input type="text" id="team-country" wire:model="country" placeholder="e.g. USA" maxlength="3"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="team-primary-color" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_primary_color') }}</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="team-primary-color" wire:model="primary_color" class="h-10 w-10 rounded cursor-pointer border-0 p-0" />
                            <input type="text" wire:model="primary_color" maxlength="7" placeholder="#B8860B"
                                   class="flex-1 rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        </div>
                        @error('primary_color') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-secondary-color" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_secondary_color') }}</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="team-secondary-color" wire:model="secondary_color" class="h-10 w-10 rounded cursor-pointer border-0 p-0" />
                            <input type="text" wire:model="secondary_color" maxlength="7" placeholder="#FFFFFF"
                                   class="flex-1 rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        </div>
                        @error('secondary_color') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-founded-year" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_founded_year') }}</label>
                        <input type="text" id="team-founded-year" wire:model="founded_year" maxlength="4" placeholder="e.g. 2024"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('founded_year') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                <span wire:loading.remove>{{ __('teams.action_create_team') }}</span>
                <span wire:loading>{{ __('common.content_creating') }}</span>
            </button>
            <a href="{{ route('teams.browse') }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('common.action_cancel') }}
            </a>
        </div>
    </div>
</div>
