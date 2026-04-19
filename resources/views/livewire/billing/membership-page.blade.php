<div class="py-8">
    <div class="max-w-5xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div class="text-center">
            <h1 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('billing.content_membership') }}</h1>
            <p class="mt-2 text-on-surface-variant">{{ __('events.content_join_the_community_and_get') }}</p>
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
                        <h3 class="font-heading font-semibold tracking-tight text-on-tertiary-container">{{ __('emails.content_membership_expiring_soon') }}</h3>
                        <p class="mt-1 text-sm text-on-tertiary-container/80">
                            @if($daysUntilExpiry <= 0)
                                {{ __('emails.content_your_membership_expires_today_renew') }}
                            @else
                                {{ __('emails.content_your_membership_expires_in_days', ['days' => $daysUntilExpiry]) }}
                            @endif
                        </p>
                        <div class="mt-3">
                            <a href="{{ route('billing.portal') }}" wire:navigate
                               class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                                {{ __('billing.action_manage_subscription') }}
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
                            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('teams.content_active_member') }}</h2>
                            <p class="text-sm text-on-surface-variant">
                                @if($subscription->onGracePeriod())
                                    <span class="text-tertiary font-medium">{{ __('common.content_canceling') }}</span> &mdash; {{ __('common.field_access_until_date', ['date' => format_date($subscription->ends_at, 'short_month_day')]) }}
                                @else
                                    {{ __('billing.content_type_plan', ['type' => ucfirst($subscription->type)]) }} &mdash;
                                    @if($subscription->ends_at)
                                        {{ __('common.field_renews_date', ['date' => format_date($subscription->ends_at, 'short_month_day')]) }}
                                    @else
                                        {{ __('common.status_active_lowercase') }}
                                    @endif
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('billing.portal') }}" wire:navigate
                       class="px-4 py-2 border border-outline-variant text-on-surface-variant rounded-lg hover:bg-surface-container-high transition-colors text-sm font-medium">
                        {{ __('billing.action_manage_billing') }}
                    </a>
                </div>
            </section>
        @endif

        {{-- Membership Plans --}}
        @if(!$subscription || !$subscription->active())
            @if($membershipTypes->count())
                <section>
                    <h2 class="text-xl font-heading font-semibold tracking-tight text-on-surface text-center mb-6">{{ __('billing.action_choose_your_plan') }}</h2>

                    <div class="grid grid-cols-1 md:grid-cols-{{ $membershipTypes->count() >= 3 ? '3' : '2' }} gap-6">
                        @foreach($membershipTypes as $plan)
                            <div class="relative bg-surface-container-lowest rounded-xl shadow-ambient
                                {{ ($plan->metadata['popular'] ?? false) ? 'ring-2 ring-primary/30' : '' }}
                                p-6 flex flex-col">
                                {{-- Popular Badge --}}
                                @if($plan->metadata['popular'] ?? false)
                                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                                        <span class="px-3 py-1 bg-gradient-to-r from-primary to-primary-container text-on-primary text-xs font-bold rounded-full tracking-wide">{{ __('billing.content_best_value') }}</span>
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
                                    <span class="text-sm text-on-surface-variant">/{{ trans_choice('billing.content_duration_months', $plan->duration_months) }}</span>
                                    @if($plan->duration_months === 12)
                                        <p class="mt-1 text-xs text-secondary font-medium">
                                            {{ format_currency((int)round($plan->price_cents / 12)) }}/month
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
                                        @endforeach>
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
                                            {{ __('billing.action_get_plan', ['plan' => $plan->name]) }}
                                        </button>
                                    @else
                                        <span class="block w-full text-center px-4 py-3 bg-surface-container-high text-on-surface-variant/60 rounded-lg text-sm font-medium cursor-not-allowed">
                                            {{ __('common.field_coming_soon') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach>
                    </div>
                </section>
            @else
                <section class="text-center py-12 bg-surface-container-lowest rounded-xl shadow-ambient">
                    <span class="material-symbols-outlined text-5xl text-on-surface-variant/50" aria-hidden="true">domain</span>
                    <h3 class="mt-4 text-lg font-medium text-on-surface">{{ __('billing.content_no_plans_available_yet') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('billing.content_membership_plans_are_coming_soon_check_back_later') }}</p>
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
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">{{ __('pages.content_community') }}</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('games.action_join_a_vibrant_community_of_tabletop_gamers') }}</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl text-primary" style="font-variation-settings: 'FILL' 1">event</span>
                    </div>
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">{{ __('games.content_unlimited_games') }}</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('campaigns.content_play_as_many_sessions_as_you_want_any_time') }}</p>
                </div>
                <div>
                    <div class="w-10 h-10 mx-auto rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl text-primary" style="font-variation-settings: 'FILL' 1">shield</span>
                    </div>
                    <h3 class="mt-2 font-heading font-semibold text-sm text-on-surface tracking-tight">{{ __('billing.content_secure_payments') }}</h3>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('billing.content_all_payments_processed_securely_via_paddle') }}</p>
                </div>
            </div>
        </section>

        {{-- FAQ / Terms --}}
        <div class="text-center text-xs text-on-surface-variant/70 space-y-1">
            <p>{{ __('billing.content_all_memberships_auto_renew_cancel') }}</p>
            <p>{{ __('common.content_by_subscribing_you_agree_to') }}</p>
        </div>
    </div>
</div>
