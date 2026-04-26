<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('game-systems') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.heading_request_game_system') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('games.content_request_game_system_subtitle') }}</p>
        </div>

        {{-- Success message --}}
        @if(session()->has('success'))
            <div class="mb-6 bg-primary-container/50 border border-primary/20 rounded-xl p-4 flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-xl mt-0.5" aria-hidden="true">check_circle</span>
                <div>
                    <p class="text-sm font-medium text-on-primary-container">{{ session('success') }}</p>
                    <button wire:click="$set('submitted', false)" class="mt-2 text-sm text-primary hover:underline">{{ __('games.action_request_another') }}</button>
                </div>
            </div>
        @endif

        @if(!$submitted)
        <form wire:submit="submit" class="space-y-6">

            {{-- Game System Details --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-secondary text-xl" aria-hidden="true">casino</span>
                    <h2 class="text-base font-semibold text-on-surface">{{ __('games.heading_system_details') }}</h2>
                </div>

                <div class="space-y-4">
                    {{-- Name --}}
                    <div>
                        <label for="request-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_system_name') }} <span class="text-error">*</span></label>
                        <input type="text"
                               id="request-name"
                               wire:model="name"
                               placeholder="{{ __('games.placeholder_system_name') }}"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Type --}}
                    <div>
                        <label for="request-type" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_system_type') }}</label>
                        <select id="request-type"
                                wire:model="type"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            <option value="boardgame">{{ __('games.type_board_game') }}</option>
                            <option value="ttrpg">{{ __('games.type_ttrpg') }}</option>
                            <option value="other">{{ __('games.type_other') }}</option>
                        </select>
                        @error('type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Additional Information --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-secondary text-xl" aria-hidden="true">info</span>
                    <h2 class="text-base font-semibold text-on-surface">{{ __('games.heading_additional_info') }}</h2>
                </div>

                <div class="space-y-4">
                    {{-- BGG URL --}}
                    <div>
                        <label for="request-bgg-url" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_bgg_url') }}</label>
                        <input type="url"
                               id="request-bgg-url"
                               wire:model="bgg_url"
                               placeholder="https://boardgamegeek.com/boardgame/..."
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('bgg_url') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Publisher --}}
                        <div>
                            <label for="request-publisher" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_publisher') }}</label>
                            <input type="text"
                                   id="request-publisher"
                                   wire:model="publisher"
                                   placeholder="{{ __('games.placeholder_publisher') }}"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('publisher') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Designer --}}
                        <div>
                            <label for="request-designer" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_designer') }}</label>
                            <input type="text"
                                   id="request-designer"
                                   wire:model="designer"
                                   placeholder="{{ __('games.placeholder_designer') }}"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('designer') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div>
                        <label for="request-notes" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_notes') }}</label>
                        <textarea id="request-notes"
                                  wire:model="notes"
                                  rows="3"
                                  placeholder="{{ __('games.placeholder_request_notes') }}"
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('notes') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Submit --}}
            <div class="flex justify-end">
                <button type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full bg-primary text-on-primary text-sm font-medium shadow-sm hover:shadow-md transition-all disabled:opacity-50">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">send</span>
                    {{ __('games.action_submit_request') }}
                    <span wire:loading class="inline-flex items-center">
                        <span class="material-symbols-outlined text-lg animate-spin" aria-hidden="true">progress_activity</span>
                    </span>
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
