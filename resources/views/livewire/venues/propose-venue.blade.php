<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('game-systems') }}" wire:navigate aria-label="{{ __('common.action_back') }}" class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('location.heading_propose_venue') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('location.content_propose_venue_subtitle') }}</p>
        </div>

        {{-- Success message --}}
        @if(session()->has('success'))
            <div class="mb-6 bg-primary-container/50 border border-primary/20 rounded-xl p-4 flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-xl mt-0.5" aria-hidden="true">check_circle</span>
                <div>
                    <p class="text-sm font-medium text-on-primary-container">{{ session('success') }}</p>
                    @if($ticketReference)
                        <p class="mt-1 text-xs text-on-primary-container/70">{{ __('location.content_proposal_reference', ['reference' => $ticketReference]) }}</p>
                    @endif
                    @if($existingLocation)
                        <p class="mt-2 text-sm text-on-primary-container/80">{{ __('location.content_proposal_existing_location', ['city' => $existingLocationCity ?? '']) }}</p>
                    @endif
                    <button wire:click="$set('submitted', false)" class="mt-2 text-sm text-primary hover:underline">{{ __('location.action_propose_another') }}</button>
                </div>
            </div>
        @endif

        @if(!$submitted)
        <form wire:submit="submit" class="space-y-6">

            {{-- Venue Details --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-secondary text-xl" aria-hidden="true">location_on</span>
                    <h2 class="text-base font-semibold text-on-surface">{{ __('location.content_venue_details') }}</h2>
                </div>

                <div class="space-y-4">
                    {{-- Name --}}
                    <div>
                        <label for="venue-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_venue_name') }} <span class="text-error">*</span></label>
                        <input type="text"
                               id="venue-name"
                               wire:model="name"
                               placeholder="{{ __('location.field_venue_name') }}"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Address --}}
                    <div>
                        <label for="venue-address" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_address') }} <span class="text-error">*</span></label>
                        <input type="text"
                               id="venue-address"
                               wire:model="address"
                               placeholder="{{ __('location.placeholder_street_address_neighborhood') }}"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- City --}}
                        <div>
                            <label for="venue-city" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_city') }} <span class="text-error">*</span></label>
                            <input type="text"
                                   id="venue-city"
                                   wire:model="city"
                                   placeholder="{{ __('location.field_enter_your_city') }}"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Postal Code --}}
                        <div>
                            <label for="venue-postal-code" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_postal_code') }}</label>
                            <input type="text"
                                   id="venue-postal-code"
                                   wire:model="postal_code"
                                   placeholder="12345"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('postal_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Country --}}
                        <div>
                            <label for="venue-country" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_country') }} <span class="text-error">*</span></label>
                            <input type="text"
                                   id="venue-country"
                                   wire:model="country"
                                   maxlength="3"
                                   placeholder="US"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Venue Type --}}
                        <div>
                            <label for="venue-type" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_venue_type') }} <span class="text-error">*</span></label>
                            <select id="venue-type"
                                    wire:model="venue_type"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                <option value="" disabled selected>{{ __('location.field_venue_type') }}</option>
                                @foreach(\App\Enums\VenueType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ __('location.type_' . $type->value) }}</option>
                                @endforeach
                            </select>
                            @error('venue_type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
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
                    {{-- Website --}}
                    <div>
                        <label for="venue-website" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_website_url') }}</label>
                        <input type="url"
                               id="venue-website"
                               wire:model="website_url"
                               placeholder="{{ __('location.placeholder_website_url') }}"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('website_url') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Proposer Notes --}}
                    <div>
                        <label for="venue-proposer-notes" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_proposer_notes') }}</label>
                        <textarea id="venue-proposer-notes"
                                  wire:model="proposer_notes"
                                  rows="3"
                                  placeholder="{{ __('location.placeholder_proposer_notes') }}"
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('proposer_notes') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Admin Notes --}}
                    <div>
                        <label for="venue-notes" class="block text-sm font-medium text-on-surface mb-1">{{ __('location.field_venue_notes') }}</label>
                        <textarea id="venue-notes"
                                  wire:model="notes"
                                  rows="2"
                                  placeholder="{{ __('location.placeholder_venue_notes') }}"
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
                    {{ __('location.action_submit_proposal') }}
                    <span wire:loading class="inline-flex items-center">
                        <span class="material-symbols-outlined text-lg animate-spin" aria-hidden="true">progress_activity</span>
                    </span>
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
