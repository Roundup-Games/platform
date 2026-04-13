<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Checkout</h1>
        </div>

        {{-- Flash Messages --}}
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-md bg-red-50 dark:bg-red-900/30 p-4" role="alert" aria-live="polite">
                <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Order Summary --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Order Summary</h2>

            @if($mode === 'subscription' && $membershipType)
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-900 dark:text-gray-100 font-medium">{{ $membershipType->name }}</span>
                        <span class="text-gray-900 dark:text-gray-100 font-bold">{{ $membershipType->formattedPrice() }}/{{ $membershipType->duration_months }}mo</span>
                    </div>
                    @if($membershipType->description)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $membershipType->description }}</p>
                    @endif
                    <p class="text-xs text-gray-400 dark:text-gray-500">Duration: {{ $membershipType->duration_months }} month{{ $membershipType->duration_months > 1 ? 's' : '' }}</p>
                </div>
            @elseif($mode === 'one-time')
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-900 dark:text-gray-100 font-medium">Event Registration</span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">One-time payment for event registration.</p>
                </div>
            @endif

            <div class="mt-6">
                <button wire:click="checkout"
                        class="w-full px-4 py-3 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-semibold uppercase tracking-wide">
                    Proceed to Payment
                </button>
            </div>
        </section>

        {{-- Back Link --}}
        <div>
            <a href="{{ route('billing.portal') }}" class="text-sm text-[#C12E26] hover:text-[#9A231F] transition-colors">&larr; Back to Billing</a>
        </div>
    </div>
</div>
