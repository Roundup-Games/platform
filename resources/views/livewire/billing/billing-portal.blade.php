<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Billing &amp; Membership</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Manage your subscription, payment methods, and invoices.</p>
        </div>

        {{-- Flash Messages --}}
        @flash
        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-md bg-green-50 dark:bg-green-900/30 p-4">
                <p class="text-sm text-green-700 dark:text-green-300">{{ session('success') }}</p>
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-md bg-red-50 dark:bg-red-900/30 p-4">
                <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Current Subscription Status --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Current Plan</h2>

            @if($subscription && $subscription->active())
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                            {{ $subscription->onGracePeriod() ? 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-300' : 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300' }}">
                            {{ $subscription->onGracePeriod() ? 'Canceling' : 'Active' }}
                        </span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ ucfirst($subscription->type) }} Plan
                        </span>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ ucfirst($subscription->status) }}</dd>
                        </div>
                        @if($subscription->ends_at)
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ $subscription->onGracePeriod() ? 'Access Until' : 'Ended At' }}</dt>
                                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $subscription->ends_at->format('M d, Y') }}</dd>
                            </div>
                        @endif
                        @if($subscription->trial_ends_at && $subscription->onTrial())
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Trial Ends</dt>
                                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $subscription->trial_ends_at->format('M d, Y') }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- Actions --}}
                    <div class="flex flex-wrap gap-3 pt-2">
                        @if($subscription->onGracePeriod())
                            <button wire:click="resumeSubscription" wire:confirm="Are you sure you want to resume your subscription?"
                                    class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                                Resume Subscription
                            </button>
                        @else
                            <button wire:click="cancelSubscription" wire:confirm="Are you sure you want to cancel? You'll keep access until the end of your billing period."
                                    class="px-4 py-2 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-sm font-medium">
                                Cancel Subscription
                            </button>
                        @endif

                        @if($portalUrl)
                            <a href="{{ $portalUrl }}" target="_blank" rel="noopener"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors text-sm font-medium">
                                Update Payment Method
                            </a>
                        @endif
                    </div>
                </div>
            @else
                <div class="text-center py-6">
                    <svg class="mx-auto w-12 h-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No active subscription</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose a plan below to get started.</p>
                </div>
            @endif
        </section>

        {{-- Available Plans --}}
        @if(!$subscription || !$subscription->active())
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Available Plans</h2>

            @if($membershipTypes->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($membershipTypes as $plan)
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-5 flex flex-col">
                            <h3 class="font-['Oswald'] font-semibold text-lg uppercase text-gray-900 dark:text-gray-100 tracking-wide">{{ $plan->name }}</h3>
                            @if($plan->description)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $plan->description }}</p>
                            @endif
                            <div class="mt-3">
                                <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $plan->formattedPrice() }}</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">/{{ $plan->duration_months }} month{{ $plan->duration_months > 1 ? 's' : '' }}</span>
                            </div>
                            <div class="mt-auto pt-4">
                                @if($plan->paddle_price_id)
                                    <a href="{{ route('billing.checkout', ['planId' => $plan->id]) }}"
                                       class="block w-full text-center px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                                        Subscribe
                                    </a>
                                @else
                                    <span class="block w-full text-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">
                                        Coming Soon
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">No membership plans are currently available.</p>
            @endif
        </section>
        @endif

        {{-- Transaction History --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Payment History</h2>

            @if($transactions->count())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Date</th>
                                <th class="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Description</th>
                                <th class="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Amount</th>
                                <th class="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="py-2 text-gray-900 dark:text-gray-100">{{ $transaction->billed_at->format('M d, Y') }}</td>
                                    <td class="py-2 text-gray-900 dark:text-gray-100">
                                        {{ $transaction->invoice_number ? 'Invoice #' . $transaction->invoice_number : 'Payment' }}
                                    </td>
                                    <td class="py-2 text-gray-900 dark:text-gray-100">
                                        {{ strtoupper($transaction->currency) }} {{ $transaction->total }}
                                    </td>
                                    <td class="py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ $transaction->status === 'completed' || $transaction->status === 'paid' ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' }}">
                                            {{ ucfirst($transaction->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-6">
                    <p class="text-sm text-gray-500 dark:text-gray-400">No payment history yet.</p>
                </div>
            @endif
        </section>
    </div>
</div>
