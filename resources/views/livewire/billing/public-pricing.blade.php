<div class="py-8">
    <div class="max-w-5xl mx-auto space-y-8">

        {{-- Hero: honest free/paid boundary up front --}}
        <div class="text-center">
            <h1 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('billing.content_pricing') }}</h1>
            <p class="mt-2 text-on-surface-variant max-w-2xl mx-auto">{{ __('billing.content_pricing_subtitle') }}</p>
        </div>

        {{-- What is always free --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <div class="flex flex-col sm:flex-row items-center gap-4 text-center sm:text-left">
                <div class="shrink-0 w-12 h-12 rounded-full bg-secondary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl text-on-secondary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">celebration</span>
                </div>
                <div>
                    <h2 class="font-heading font-semibold tracking-tight text-on-surface">{{ __('billing.content_pricing_free_heading') }}</h2>
                    <p class="mt-1 text-sm text-on-surface-variant">{{ __('billing.content_creating_events_and_registering_for') }}</p>
                </div>
            </div>
        </section>

        {{-- Membership Tiers (same DB source as /membership) --}}
        @if($membershipTypes->count())
            <section>
                <h2 class="text-xl font-heading font-semibold tracking-tight text-on-surface text-center mb-2">{{ __('billing.content_membership') }}</h2>
                <p class="text-sm text-on-surface-variant text-center mb-6 max-w-2xl mx-auto">{{ __('billing.content_pricing_membership_pitch') }}</p>

                <div class="grid grid-cols-1 md:grid-cols-{{ $membershipTypes->count() >= 3 ? '3' : '2' }} gap-6">
                    @foreach($membershipTypes as $plan)
                        <div class="relative bg-surface-container-lowest rounded-xl shadow-ambient
                            {{ ($plan->metadata['popular'] ?? false) ? 'ring-2 ring-primary/30' : '' }}
                            p-6 flex flex-col">
                            {{-- Popular Badge --}}
                            @if($plan->metadata['popular'] ?? false)
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                                    <span class="px-3 py-1 bg-primary text-on-primary text-xs font-bold rounded-full tracking-wide">{{ __('billing.content_best_value') }}</span>
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
                                @if($plan->price_cents === 0)
                                    <span class="text-3xl font-bold text-secondary">{{ __('common.price_free') }}</span>
                                @else
                                    <span class="text-3xl font-bold text-on-surface">{{ format_currency($plan->price_cents) }}</span>
                                    <span class="text-sm text-on-surface-variant">/{{ trans_choice('billing.content_duration_months', $plan->duration_months) }}</span>
                                    @if($plan->duration_months === 12)
                                        <p class="mt-1 text-xs text-secondary font-medium">
                                            {{ format_currency((int) round($plan->price_cents / 12)) }}{{ __('billing.label_per_month_short') }}
                                        </p>
                                    @endif
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

                            {{-- CTA: guests sign up, members continue to /membership for checkout --}}
                            <div class="mt-auto pt-6">
                                @auth
                                    <a href="{{ route('membership') }}" wire:navigate
                                       class="block w-full text-center px-4 py-3 rounded-lg text-sm font-semibold tracking-wide transition-all active:scale-95
                                           {{ ($plan->metadata['popular'] ?? false)
                                               ? 'bg-primary text-on-primary shadow-ambient hover:brightness-110'
                                               : 'border border-primary text-primary hover:bg-primary hover:text-on-primary' }}">
                                        {{ __('billing.action_choose_your_plan') }}
                                    </a>
                                @else
                                    <a href="{{ route('register') }}" wire:navigate
                                       class="block w-full text-center px-4 py-3 rounded-lg text-sm font-semibold tracking-wide transition-all active:scale-95
                                           {{ ($plan->metadata['popular'] ?? false)
                                               ? 'bg-primary text-on-primary shadow-ambient hover:brightness-110'
                                               : 'border border-primary text-primary hover:bg-primary hover:text-on-primary' }}">
                                        {{ __('billing.action_sign_up_free') }}
                                    </a>
                                @endauth
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            <section class="text-center py-12 bg-surface-container-lowest rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/50" aria-hidden="true">domain</span>
                <h3 class="mt-4 text-lg font-medium text-on-surface">{{ __('billing.content_no_plans_available_yet') }}</h3>
                <p class="mt-2 text-sm text-on-surface-variant">{{ __('billing.content_membership_plans_are_coming_soon_check_back_later') }}</p>
            </section>
        @endif

        {{-- Notes --}}
        <div class="text-center text-xs text-on-surface-variant/70 space-y-1">
            <p>{{ __('billing.content_all_memberships_auto_renew_cancel') }}</p>
            <p>{{ __('billing.content_all_payments_processed_securely_via_paddle') }}</p>
        </div>
    </div>
</div>
