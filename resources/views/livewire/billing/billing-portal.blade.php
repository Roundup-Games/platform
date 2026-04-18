<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('billing.content_billing_membership') }}</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('billing.action_manage_your_subscription_payment_methods') }}</p>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ session('success') }}
                </p>
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
                 class="rounded-lg bg-error-container p-4" role="alert" aria-live="polite">
                <p class="text-sm text-on-error-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">error</span>
                    {{ session('error') }}
                </p>
            </div>
        @endif

        {{-- Current Subscription Status --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('billing.content_current_plan') }}</h2>

            @if($subscription && $subscription->active())
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                            {{ $subscription->onGracePeriod() ? 'bg-tertiary-container text-on-tertiary-container' : 'bg-secondary-container text-on-secondary-container' }}">
                            {{ $subscription->onGracePeriod() ? __('common.content_canceling') : __('common.status_active') }}
                        </span>
                        <span class="text-sm text-on-surface-variant">
                            {{ __('billing.content_type_plan', ['type' => ucfirst($subscription->type)]) }}
                        </span>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-on-surface-variant">{{ __('common.content_status') }}</dt>
                            <dd class="font-medium text-on-surface">{{ ucfirst($subscription->status) }}</dd>
                        </div>
                        @if($subscription->ends_at)
                            <div>
                                <dt class="text-on-surface-variant">{{ $subscription->onGracePeriod() ? __('common.content_access_until') : __('common.field_ended_at') }}</dt>
                                <dd class="font-medium text-on-surface">{{ format_date($subscription->ends_at, 'short_month_day') }}</dd>
                            </div>
                        @endif
                        @if($subscription->trial_ends_at && $subscription->onTrial())
                            <div>
                                <dt class="text-on-surface-variant">{{ __('common.field_trial_ends') }}</dt>
                                <dd class="font-medium text-on-surface">{{ format_date($subscription->trial_ends_at, 'short_month_day') }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- Actions --}}
                    <div class="flex flex-wrap gap-3 pt-2">
                        @if($subscription->onGracePeriod())
                            <button wire:click="resumeSubscription" wire:confirm="{{ __('billing.flash_are_you_sure_you_want_to_resume_your_subscription') }}"
                                    class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                                {{ __('billing.action_resume_subscription') }}
                            </button>
                        @else
                            <button wire:click="cancelSubscription" wire:confirm="{{ __("billing.error_are_you_sure_you_want_to_cancel_you_ll_keep_access") }}"
                                    class="px-4 py-2 border border-error/40 text-error rounded-lg hover:bg-error-container transition-colors text-sm font-medium">
                                {{ __('billing.action_cancel_subscription') }}
                            </button>
                        @endif

                        @if($portalUrl)
                            <a href="{{ $portalUrl }}" target="_blank" rel="noopener"
                               class="px-4 py-2 border border-outline-variant text-on-surface-variant rounded-lg hover:bg-surface-container-high transition-colors text-sm font-medium">
                                {{ __('billing.field_update_payment_method') }}
                            </a>
                        @endif
                    </div>
                </div>
            @else
                <div class="text-center py-6">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant/50" aria-hidden="true">account_balance_wallet</span>
                    <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('billing.error_no_active_subscription') }}</h3>
                    <p class="mt-1 text-sm text-on-surface-variant">{{ __('billing.action_choose_a_plan_below_to_get_started') }}</p>
                </div>
            @endif
        </section>

        {{-- Available Plans --}}
        @if(!$subscription || !$subscription->active())
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('billing.content_available_plans') }}</h2>

            @if($membershipTypes->count())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($membershipTypes as $plan)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-5 flex flex-col">
                            <h3 class="font-heading font-semibold text-lg tracking-tight text-on-surface">{{ $plan->name }}</h3>
                            @if($plan->description)
                                <p class="mt-1 text-sm text-on-surface-variant">{{ $plan->description }}</p>
                            @endif
                            <div class="mt-3">
                                <span class="text-2xl font-bold text-on-surface">{{ $plan->formattedPrice() }}</span>
                                <span class="text-sm text-on-surface-variant">/{{ trans_choice(':count month|:count months', $plan->duration_months) }}</span>
                            </div>
                            <div class="mt-auto pt-4">
                                @if($plan->paddle_price_id)
                                    <a href="{{ route('billing.checkout', ['planId' => $plan->id]) }}" wire:navigate
                                       class="block w-full text-center px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                                        {{ __('common.content_subscribe') }}
                                    </a>
                                @else
                                    <span class="block w-full text-center px-4 py-2 bg-surface-container-high text-on-surface-variant/60 rounded-lg text-sm font-medium cursor-not-allowed">
                                        {{ __('common.field_coming_soon') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach>
                </div>
            @else
                <p class="text-sm text-on-surface-variant">{{ __('billing.content_no_membership_plans_are_currently_available') }}</p>
            @endif
        </section>
        @endif

        {{-- Transaction History --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4">{{ __('billing.content_payment_history') }}</h2>

            @if($transactions->count())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-outline-variant">
                                <th class="text-left py-2 text-on-surface-variant font-medium">{{ __('common.field_date') }}</th>
                                <th class="text-left py-2 text-on-surface-variant font-medium">{{ __('common.field_description') }}</th>
                                <th class="text-left py-2 text-on-surface-variant font-medium">{{ __('billing.field_amount') }}</th>
                                <th class="text-left py-2 text-on-surface-variant font-medium">{{ __('common.content_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr class="border-b border-outline-variant/30">
                                    <td class="py-2 text-on-surface">{{ format_date($transaction->billed_at, 'short_month_day') }}</td>
                                    <td class="py-2 text-on-surface">
                                        {{ $transaction->invoice_number ? __('billing.content_invoice_number', ['number' => $transaction->invoice_number]) : __('billing.content_payment') }}
                                    </td>
                                    <td class="py-2 text-on-surface">
                                        {{ strtoupper($transaction->currency) }} {{ $transaction->total }}
                                    </td>
                                    <td class="py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ $transaction->status === 'completed' || $transaction->status === 'paid' ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant' }}">
                                            {{ ucfirst($transaction->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach>
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-6">
                    <p class="text-sm text-on-surface-variant">{{ __('billing.content_no_payment_history_yet') }}</p>
                </div>
            @endif>
        </section>
    </div>
</div>
