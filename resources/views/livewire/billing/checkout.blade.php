<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('common.content_checkout') }}</h1>
        </div>

        {{-- Flash Messages --}}
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-lg bg-error-container p-4" role="alert" aria-live="polite">
                <p class="text-sm text-on-error-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">error</span>
                    {{ session('error') }}
                </p>
            </div>
        @endif

        {{-- Order Summary --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('common.content_order_summary') }}</h2>

            @if($mode === 'subscription' && $membershipType)
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-on-surface font-medium">{{ $membershipType->name }}</span>
                        <span class="text-on-surface font-bold">{{ $membershipType->formattedPrice() }}/{{ $membershipType->duration_months }}mo</span>
                    </div>
                    @if($membershipType->description)
                        <p class="text-sm text-on-surface-variant">{{ $membershipType->description }}</p>
                    @endif
                    <p class="text-xs text-on-surface-variant/70">{{ __('common.content_duration') }} {{ trans_choice('billing.content_duration_months', $membershipType->duration_months) }}</p>
                </div>
            @elseif($mode === 'one-time')
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-on-surface font-medium">{{ __('events.content_event_registration') }}</span>
                    </div>
                    <p class="text-sm text-on-surface-variant">{{ __('billing.content_one_time_payment_for_event_registration') }}</p>
                </div>
            @endif

            <div class="mt-6">
                <button wire:click="checkout"
                        class="w-full px-4 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-semibold tracking-wide">
                    {{ __('billing.action_proceed_to_payment') }}
                </button>
            </div>
        </section>

        {{-- Back Link --}}
        <div>
            <a href="{{ route('billing.portal') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary transition-colors">
                <span class="material-symbols-outlined text-base">arrow_back</span>
                {{ __('billing.action_back_to_billing') }}
            </a>
        </div>
    </div>
</div>
