<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('venues.detail', ['locale' => app()->getLocale(), 'slug' => $location->slug]) }}" wire:navigate aria-label="{{ __('common.action_back') }}" class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('venue.heading_claim_venue') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('venue.content_claim_subtitle', ['name' => $location->name]) }}</p>
        </div>

        {{-- Success message --}}
        @if(session()->has('success'))
            <div class="mb-6 bg-primary-container/50 border border-primary/20 rounded-xl p-4 flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-xl mt-0.5" aria-hidden="true">check_circle</span>
                <div>
                    <p class="text-sm font-medium text-on-primary-container">{{ session('success') }}</p>
                    @if($ticketReference)
                        <p class="mt-1 text-xs text-on-primary-container/70">{{ __('venue.content_claim_reference', ['reference' => $ticketReference]) }}</p>
                    @endif
                    <a href="{{ route('venues.detail', ['locale' => app()->getLocale(), 'slug' => $location->slug]) }}" wire:navigate class="mt-2 inline-block text-sm text-primary hover:underline">{{ __('venue.action_back_to_venue') }}</a>
                </div>
            </div>
        @endif

        @if(!$submitted)
        <form wire:submit="submit" class="space-y-6">

            {{-- Why are you claiming this venue? --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-secondary text-xl" aria-hidden="true">verified_user</span>
                    <h2 class="text-base font-semibold text-on-surface">{{ __('venue.field_justification') }}</h2>
                </div>

                <div class="space-y-4">
                    {{-- Justification (required) --}}
                    <div>
                        <label for="claim-justification" class="block text-sm font-medium text-on-surface mb-1">{{ __('venue.field_justification') }} <span class="text-error">*</span></label>
                        <textarea id="claim-justification"
                                  wire:model="justification"
                                  rows="4"
                                  placeholder="{{ __('venue.placeholder_justification') }}"
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('justification') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Optional proof --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-secondary text-xl" aria-hidden="true">info</span>
                    <h2 class="text-base font-semibold text-on-surface">{{ __('venue.heading_optional_proof') }}</h2>
                </div>

                <div class="space-y-4">
                    {{-- Website --}}
                    <div>
                        <label for="claim-website" class="block text-sm font-medium text-on-surface mb-1">{{ __('venue.field_website') }}</label>
                        <input type="url"
                               id="claim-website"
                               wire:model="website_url"
                               placeholder="{{ __('venue.placeholder_claim_website') }}"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('website_url') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Contact email --}}
                    <div>
                        <label for="claim-contact-email" class="block text-sm font-medium text-on-surface mb-1">{{ __('venue.field_contact_email') }}</label>
                        <input type="email"
                               id="claim-contact-email"
                               wire:model="contact_email"
                               placeholder="you@example.com"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('venue.hint_contact_email') }}</p>
                        @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Submit --}}
            <div class="flex justify-end">
                <button type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full bg-primary text-on-primary text-sm font-medium shadow-xs hover:shadow-md transition-all disabled:opacity-50">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">send</span>
                    {{ __('venue.action_submit_claim') }}
                    <span wire:loading class="inline-flex items-center">
                        <span class="material-symbols-outlined text-lg animate-spin" aria-hidden="true">progress_activity</span>
                    </span>
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
