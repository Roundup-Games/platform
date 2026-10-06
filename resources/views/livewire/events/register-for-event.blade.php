<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('events.action_back_to_event', ['event' => $event->name]) }}
            </a>
        </div>
    </div>

    {{-- Header --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('events.action_register_for_event') }}</h1>
            <p class="mt-2 text-on-primary/80">{{ $event->name }}</p>
        </div>
    </section>

    {{-- Form --}}
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8 bg-surface">
        {{-- Flash messages --}}
        @if(session()->has('error'))
            <div class="mb-6 bg-error-container border border-error/20 rounded-lg p-4 text-sm text-on-error-container" role="alert" aria-live="polite">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="register" class="space-y-6">
            {{-- Notes --}}
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('common.field_additional_notes') }}</h2>
                <textarea id="registration-notes" wire:model="notes" rows="3" placeholder="{{ __('common.content_any_special_requests_dietary_requirements') }}"
                    class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm"></textarea>
                @error('notes')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Fee Summary --}}
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('billing.field_fee_summary') }}</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">
                            {{ __('events.content_registration') }}
                        </span>
                        <span class="text-on-surface">
                            {{ ($event->individual_registration_fee ?? 0) > 0 ? format_currency($event->individual_registration_fee) : __('common.price_free') }}
                        </span>
                    </div>
                    @if($this->isEarlyBird && $event->early_bird_discount > 0)
                        <div class="flex justify-between text-secondary">
                            <span>{{ __('billing.content_early_bird_discount') }}</span>
                            <span>-{{ format_currency($event->early_bird_discount) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-2 border-t border-outline-variant font-medium">
                        <span class="text-on-surface">{{ __('common.content_total') }}</span>
                        <span class="text-on-surface {{ $this->effectiveFee === 0 ? 'text-secondary' : '' }}">
                            {{ $this->effectiveFee > 0 ? format_currency($this->effectiveFee) : __('common.price_free') }}
                        </span>
                    </div>
                </div>
                @if($this->isEarlyBird)
                    <p class="mt-3 text-xs text-secondary">
                        {{ __('billing.field_early_bird_pricing_ends_date', ['date' => format_date($event->early_bird_deadline, 'datetime')]) }}
                    </p>
                @endif
            </div>

            {{-- Submit --}}
            <div class="flex items-center justify-between">
                <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-on-surface-variant hover:text-on-surface">
                    {{ __('common.action_cancel') }}
                </a>
                <button type="submit"
                    class="px-6 py-3 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ $this->effectiveFee > 0 ? __('billing.action_proceed_to_payment') : __('events.content_complete_registration') }}
                </button>
            </div>
        </form>
    </div>
</div>
