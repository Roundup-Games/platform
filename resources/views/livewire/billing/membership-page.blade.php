<div class="py-8">
    <div class="max-w-5xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div class="text-center">
            <h1 class="text-3xl font-heading font-bold tracking-tight text-on-surface">Membership</h1>
            <p class="mt-2 text-on-surface-variant">Join the community and get access to games, campaigns, and events.</p>
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

        {{-- Renewal Prompt for Expiring Memberships --}}
        @if($expiringSoon)
            <div class="rounded-lg bg-tertiary-container p-6">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <span class="material-symbols-outlined text-2xl text-on-tertiary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">warning</span>
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold tracking-tight text-on-tertiary-container">Membership Expiring Soon</h3>
                        <p class="mt-1 text-sm text-on-tertiary-container/80">
                            @if($daysUntilExpiry <= 0)
                                Your membership expires today! Renew now to keep your access.
                            @else
                                Your membership expires in {{ $daysUntilExpiry }} day{{ $daysUntilExpiry !== 1 ? 's' : '' }}. Renew now to avoid interruption.
                            @endif
                        </p>
                        <div class="mt-3">
                            <a href="{{ route('billing.portal') }}" wire:navigate
                               class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                                Manage Subscription
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Current Membership Status --}}
        @if($subscription && $subscription->active())
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-secondary-container flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl text-on-secondary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                        </div>
                        <div>
                            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">Active Member</h2>
                            <p class="text-sm text-on-surface-variant">
                                @if($subscription->onGracePeriod())
                                    <span class="text-tertiary font-medium">Canceling</span> &mdash; access until {{ $subscription->ends_at?->format('M d, Y') }}
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
                    <a href="{{ route('billing.portal') }}" wire:navigate
                       class="px-4 py-2 border border-outline-variant text-on-surface-variant rounded-lg hover:bg-surface-container-high transition-colors text-sm font-medium">
                        Manage Billing
                    </a>
                </div>
            </section>
        @endif

        {{-- Membership Plans --}}
        @if(!$subscription || !$subscription->active())
            @if($membershipTypes->count())
                <section>
                    <h2 class="text-xl font-heading font-semibold tracking-tight text-on-surface text-center mb-6">Choose Your Plan</h2>

                    <div class="grid grid-cols-1 md:grid-cols-{{ $membershipTypes->count() >= 3 ? '3' : '2' }} gap-6">
                        @foreach($membershipTypes as $plan)
                            <div class="relative bg-surface-container-lowest rounded-xl shadow-ambient
                                {{ ($plan->metadata['popular'] ?? false) ? 'ring-2 ring-primary/30' : '' }}
                                p-6 flex flex-col">
                                {{-- Popular Badge --}}
                                @if($plan->metadata['popular'] ?? false)
                                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                                        <span class="px-3 py-1 bg-gradient-to-r from-primary to-primary-container text-on-primary text-xs font-bold rounded-full tracking-wide">Best Value</span>
                                    </div>
                                @endif

                                {{-- Plan Details --}}
                                <div class="text-center">
                                    <h3 class="font-heading font-semibold text-xl tracking-tight text-on-surface">{{ $plan->name }}</h3>
                                    @if($plan->description)
                                        <p class="mt-2 text-sm text-on-surface-variant">{{ $plan->description }}</p>
                                    @endif
                                </div>

                                {{-- Price --}}
                                <div class="mt-4 text-center">
                                    <span class="text-3xl font-bold text-on-surface">{{ $plan->formattedPrice() }}</span>
                                    <span class="text-sm text-on-surface-variant">/{{ $plan->duration_months }} month{{ $plan->duration_months > 1 ? 's' : '' }}</span>
                                    @if($plan->duration_months === 12)
                                        <p class="mt-1 text-xs text-secondary font-medium">
                                            {{ number_format($plan->price_cents / 12 / 100, 2) }}/month
                                        </p>
                                    @endif
                                </div>

                                {{-- Features --}}
                                @if($plan->metadata['features'] ?? [])
                                    <ul class="mt-4 space-y-2 text-sm text-on-surface-variant">
                                        @foreach($plan->metadata['features'] as $feature)
                                            <li class="flex items-center gap-2">
                                                <span class="material-symbols-outlined text-sm text-secondary" style="font-variation-settings: 'FILL' 1">check_circle</span>
                                                {{ $feature }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                {{-- CTA --}}
                                <div class="mt-auto pt-6">
                                    @if($plan->paddle_price_id)
                                        <button wire:click="initiateCheckout({{ $plan->id }})"
                                                class="w-full px-4 py-3 rounded-lg text-sm font-semibold tracking-wide transition-all active:scale-95
                                                    {{ ($plan->metadata['popular'] ?? false)
                                                        ? 'bg-gradient-to-r from-primary to-primary-container text-on-primary shadow-ambient hover:brightness-110'
                                                        : 'border border-primary text-primary hover:bg-primary hover:text-on-primary' }}">
                                            Get {{ $plan->name }}
                                        </button>
                                    @else
                                        <span class="block w-full text-center px-4 py-3 bg-surface-container-high text-on-surface-variant/60 rounded-lg text-sm font-medium cursor-not-allowed">
                                            Coming Soon
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @else
                <section class="text-center py-12 bg-surface-container-lowest rounded-xl shadow-ambient">
                    <span class="material-symbols-outlined text-5xl text-on-surface-variant/50" aria-hidden="true">domain</span>
                    <h3 class="mt-4 text-lg font-medium text-on-surface">No Plans Available Yet</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">Membership plans are coming soon. Check back later!</p>
                </section>
            @endif
        @endif

        {{-- Info Section --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl text-primary" style="font-variation-settings: 'FILL' 1">groups</span>
                    </div>
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">Community</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">Join a vibrant community of tabletop gamers.</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl text-primary" style="font-variation-settings: 'FILL' 1">event</span>
                    </div>
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">Unlimited Games</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">Play as many sessions as you want, any time.</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl text-primary" style="font-variation-settings: 'FILL' 1">shield</span>
                    </div>
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">Secure Payments</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">All payments processed securely via Paddle.</p>
                </div>
            </div>
        </section>

        {{-- FAQ / Terms --}}
        <div class="text-center text-xs text-on-surface-variant/70 space-y-1">
            <p>All memberships auto-renew. Cancel anytime from your billing portal.</p>
            <p>By subscribing, you agree to our terms of service and privacy policy.</p>
        </div>
    </div>
</div>
