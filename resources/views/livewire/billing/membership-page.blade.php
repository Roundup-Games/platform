<div class="py-8">
    <div class="max-w-5xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div class="text-center">
            <h1 class="text-3xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Membership</h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400">Join the community and get access to games, campaigns, and events.</p>
        </div>

        {{-- Flash Messages --}}
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-md bg-red-50 dark:bg-red-900/30 p-4">
                <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Renewal Prompt for Expiring Memberships --}}
        @if($expiringSoon)
            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 p-6">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <svg class="w-8 h-8 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-['Oswald'] font-semibold uppercase text-amber-800 dark:text-amber-200 tracking-wide">Membership Expiring Soon</h3>
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                            @if($daysUntilExpiry <= 0)
                                Your membership expires today! Renew now to keep your access.
                            @else
                                Your membership expires in {{ $daysUntilExpiry }} day{{ $daysUntilExpiry !== 1 ? 's' : '' }}. Renew now to avoid interruption.
                            @endif
                        </p>
                        <div class="mt-3">
                            <a href="{{ route('billing.portal') }}"
                               class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm font-medium">
                                Manage Subscription
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Current Membership Status --}}
        @if($subscription && $subscription->active())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/40 flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Active Member</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if($subscription->onGracePeriod())
                                    <span class="text-yellow-600 dark:text-yellow-400 font-medium">Canceling</span> &mdash; access until {{ $subscription->ends_at?->format('M d, Y') }}
                                @else
                                    {{ ucfirst($subscription->type) }} Plan &mdash;
                                    @if($subscription->ends_at)
                                        renews {{ $subscription->ends_at->format('M d, Y') }}
                                    @else
                                        active
                                    @endif
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('billing.portal') }}"
                       class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors text-sm font-medium">
                        Manage Billing
                    </a>
                </div>
            </section>
        @endif

        {{-- Membership Plans --}}
        @if(!$subscription || !$subscription->active())
            @if($membershipTypes->count())
                <section>
                    <h2 class="text-xl font-['Oswald'] font-semibold uppercase text-gray-900 dark:text-gray-100 tracking-wide text-center mb-6">Choose Your Plan</h2>

                    <div class="grid grid-cols-1 md:grid-cols-{{ $membershipTypes->count() >= 3 ? '3' : '2' }} gap-6">
                        @foreach($membershipTypes as $plan)
                            <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-sm border
                                {{ ($plan->metadata['popular'] ?? false) ? 'border-[#C12E26] dark:border-[#C12E26] ring-2 ring-[#C12E26]/20' : 'border-gray-200 dark:border-gray-700' }}
                                p-6 flex flex-col">
                                {{-- Popular Badge --}}
                                @if($plan->metadata['popular'] ?? false)
                                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                                        <span class="px-3 py-1 bg-[#C12E26] text-white text-xs font-bold uppercase rounded-full tracking-wide">Best Value</span>
                                    </div>
                                @endif

                                {{-- Plan Details --}}
                                <div class="text-center">
                                    <h3 class="font-['Oswald'] font-semibold text-xl uppercase text-gray-900 dark:text-gray-100 tracking-wide">{{ $plan->name }}</h3>
                                    @if($plan->description)
                                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $plan->description }}</p>
                                    @endif
                                </div>

                                {{-- Price --}}
                                <div class="mt-4 text-center">
                                    <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $plan->formattedPrice() }}</span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">/{{ $plan->duration_months }} month{{ $plan->duration_months > 1 ? 's' : '' }}</span>
                                    @if($plan->duration_months === 12)
                                        <p class="mt-1 text-xs text-green-600 dark:text-green-400 font-medium">
                                            {{ number_format($plan->price_cents / 12 / 100, 2) }}/month
                                        </p>
                                    @endif
                                </div>

                                {{-- Features --}}
                                @if($plan->metadata['features'] ?? [])
                                    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                                        @foreach($plan->metadata['features'] as $feature)
                                            <li class="flex items-center gap-2">
                                                <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                {{ $feature }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                {{-- CTA --}}
                                <div class="mt-auto pt-6">
                                    @if($plan->paddle_price_id)
                                        <button wire:click="initiateCheckout({{ $plan->id }})"
                                                class="w-full px-4 py-3 rounded-lg text-sm font-semibold uppercase tracking-wide transition-colors
                                                    {{ ($plan->metadata['popular'] ?? false)
                                                        ? 'bg-[#C12E26] text-white hover:bg-[#9A231F]'
                                                        : 'border border-[#C12E26] text-[#C12E26] hover:bg-[#C12E26] hover:text-white dark:hover:bg-[#C12E26]' }}">
                                            Get {{ $plan->name }}
                                        </button>
                                    @else
                                        <span class="block w-full text-center px-4 py-3 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed">
                                            Coming Soon
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @else
                <section class="text-center py-12 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                    <svg class="mx-auto w-16 h-16 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-gray-100">No Plans Available Yet</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Membership plans are coming soon. Check back later!</p>
                </section>
            @endif
        @endif

        {{-- Info Section --}}
        <section class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-[#C12E26]/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <h3 class="mt-2 font-['Oswald'] font-semibold uppercase text-sm text-gray-900 dark:text-gray-100 tracking-wide">Community</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Join a vibrant community of tabletop gamers.</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-[#C12E26]/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="mt-2 font-['Oswald'] font-semibold uppercase text-sm text-gray-900 dark:text-gray-100 tracking-wide">Unlimited Games</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Play as many sessions as you want, any time.</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-[#C12E26]/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <h3 class="mt-2 font-['Oswald'] font-semibold uppercase text-sm text-gray-900 dark:text-gray-100 tracking-wide">Secure Payments</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">All payments processed securely via Paddle.</p>
                </div>
            </div>
        </section>

        {{-- FAQ / Terms --}}
        <div class="text-center text-xs text-gray-400 dark:text-gray-500 space-y-1">
            <p>All memberships auto-renew. Cancel anytime from your billing portal.</p>
            <p>By subscribing, you agree to our terms of service and privacy policy.</p>
        </div>
    </div>
</div>
