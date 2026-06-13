<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.action_manage_team') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{!! __('profile.field_update_settings_for_name', ['name' => '<strong>' . e($team->name) . '</strong>']) !!}</p>
        </div>

        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ __('teams.flash_team_settings_saved_successfully') }}
                </p>
            </div>
        @endif

        {{-- Team Settings Form --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface tracking-tight mb-4">{{ __('teams.content_team_information') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="team-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('teams.field_team_name') }} <span class="text-error">*</span></label>
                    <input type="text" id="team-name" wire:model="name"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                {{-- Translatable fields (description) rendered via locale-aware section --}}
                @php
                    $allLocales = $this->getAllLocales();
                    $baselineLocale = $this->getBaselineLocale();
                @endphp
                <x-forms.translatable-section
                    :fields="[
                        ['name' => 'description', 'label' => __('common.field_description'), 'type' => 'textarea', 'rows' => 3],
                    ]"
                    :active-locale="$activeLocale"
                    :baseline-locale="$baselineLocale"
                    :all-locales="$allLocales"
                    inputClass="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant"
                />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="team-city" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_city') }}</label>
                        <input type="text" id="team-city" wire:model="city"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-country" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_country') }}</label>
                        <input type="text" id="team-country" wire:model="country" maxlength="3"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="team-primary-color" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_primary_color') }}</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="team-primary-color" wire:model="primary_color" class="h-10 w-10 rounded-sm cursor-pointer border-0 p-0" />
                            <input type="text" wire:model="primary_color" maxlength="7"
                                   class="flex-1 rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        </div>
                        @error('primary_color') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-secondary-color" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_secondary_color') }}</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="team-secondary-color" wire:model="secondary_color" class="h-10 w-10 rounded-sm cursor-pointer border-0 p-0" />
                            <input type="text" wire:model="secondary_color" maxlength="7"
                                   class="flex-1 rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                        </div>
                        @error('secondary_color') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="team-founded-year" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_founded_year') }}</label>
                        <input type="text" id="team-founded-year" wire:model="founded_year" maxlength="4"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('founded_year') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Danger Zone --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
            <h2 class="text-lg font-heading font-semibold text-error mb-1 tracking-tight">{{ __('common.content_danger_zone') }}</h2>
            <p class="text-sm text-on-surface-variant mb-4">{{ __('teams.error_permanently_delete_this_team_this_cannot_be_undone') }}</p>
            <button onclick="confirm('{{ __('teams.flash_are_you_sure_you_want_to_delete_this_team') }}') || event.preventDefault()"
                    wire:click="deleteTeam"
                    class="px-4 py-2 bg-error text-on-primary rounded-lg hover:brightness-110 transition-all text-sm font-medium">
                {{ __('teams.action_delete_team') }}
            </button>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
                <span wire:loading>{{ __('common.content_saving') }}</span>
            </button>
            <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('common.action_cancel') }}
            </a>
        </div>
    </div>
</div>
