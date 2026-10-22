<?php

namespace App\Livewire\Billing;

use App\Enums\LocalSubscriptionSource;
use App\Models\MembershipType;
use App\Services\GmRoleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class BillingPortal extends Component
{
    public ?string $portalUrl = null;

    public ?string $confirmingAction = null;

    public function mount(): void
    {
        $user = authenticatedUser();

        // Build the Paddle customer portal URL if the user has a Paddle ID
        if ($user->paddle_id) {
            $this->portalUrl = "https://{$this->paddleVendorDomain()}/customer/login?customer_id={$user->paddle_id}";
        }
    }

    public function cancelSubscription(): void
    {
        $user = authenticatedUser();
        $subscription = $user->subscription();

        if (! $subscription || ! $subscription->active()) {
            session()->flash('error', __('billing.error_no_active_subscription_to_cancel'));

            return;
        }

        Log::info('User initiated subscription cancellation', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'paddle_subscription_id' => $subscription->paddle_id,
        ]);

        $subscription->cancel();

        // Benefits follow the LAST active membership — the invariant is
        // single-sourced inside GmRoleService::handleSubscriptionLapse()
        // (a live hasActiveMembership() re-check decides the lapse):
        // canceling the Paddle relationship never strips GM benefits
        // while any active membership remains — a surviving comp or
        // self-serve GM plan keeps them. With no other membership,
        // Cashier keeps the canceled sub valid through its paid-out
        // grace period (subscribed() = active OR onGracePeriod), so
        // benefits ride the grace period and lapse when the
        // subscription.canceled webhook ends the membership — identical
        // to a cancellation made directly in the Paddle customer portal.
        // (The webhook's own retain-guard is deliberately narrower — see
        // GmRoleService::handleSubscriptionLapse's predicate note.)
        if ($user->isGM()) {
            app(GmRoleService::class)->handleSubscriptionLapse($user);
        }

        Log::info('Subscription canceled successfully', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ]);

        session()->flash('success', __('billing.content_your_subscription_has_been_canceled'));
    }

    public function resumeSubscription(): void
    {
        $user = authenticatedUser();
        $subscription = $user->subscription();

        if (! $subscription || ! $subscription->onGracePeriod()) {
            session()->flash('error', __('billing.content_no_subscription_available_to_resume'));

            return;
        }

        Log::info('User initiated subscription resume', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'paddle_subscription_id' => $subscription->paddle_id,
        ]);

        $subscription->resume();

        // Re-assign GM role if subscription resumed
        if (! $user->isGM() && $user->gmProfile) {
            app(GmRoleService::class)->assignGMRole($user);
        }

        Log::info('Subscription resumed successfully', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        session()->flash('success', __('billing.content_your_subscription_has_been_resumed_welcome_back'));
    }

    public function cancelGmSubscription(): void
    {
        $user = authenticatedUser();

        Log::info('User initiated GM subscription cancellation', [
            'user_id' => $user->id,
        ]);

        // Self-serve only: a comp grant is ended by its granter
        // (CompMembershipService::withdraw), never through this portal.
        // The boolean return decides the flash — nothing canceled means
        // there was nothing this action could cancel.
        if (! app(GmRoleService::class)->deactivateGmSubscription($user)) {
            session()->flash('error', __('billing.error_no_active_gm_subscription_to_cancel'));

            return;
        }

        session()->flash('success', __('billing.content_gm_subscription_canceled'));
    }

    public function reactivateGmSubscription(): void
    {
        $user = authenticatedUser();

        // Reactivate is a self-serve concern only: a canceled/withdrawn
        // comp row must never be revivable from the member portal.
        $canceledGmSub = $user->localSubscriptions()
            ->whereHas('membershipType', fn ($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->where('source', LocalSubscriptionSource::SelfServe)
            ->where('status', 'canceled')
            ->first();

        if (! $canceledGmSub) {
            session()->flash('error', __('billing.error_no_gm_subscription_to_reactivate'));

            return;
        }

        Log::info('User reactivated GM subscription', [
            'user_id' => $user->id,
        ]);

        // Reactivate on the tier the user actually had: a plan-less
        // call would fall back to the cheapest gm_plan tier, silently
        // downgrading a canceled Game Master+ row to the base plan. A
        // tier that is no longer offered falls back to that resolution
        // instead of activating onto a deactivated plan.
        $canceledPlan = $canceledGmSub->membershipType;
        app(GmRoleService::class)->activateGmSubscription(
            $user,
            ($canceledPlan !== null && $canceledPlan->status === 'active') ? $canceledPlan : null,
        );

        session()->flash('success', __('billing.content_gm_subscription_reactivated'));
    }

    public function activateLocalPlan(string $planId): void
    {
        $user = authenticatedUser();
        $plan = MembershipType::active()->findOrFail($planId);

        if ($plan->type !== 'local') {
            session()->flash('error', __('billing.error_this_plan_is_not_available_for_purchase_yet'));

            return;
        }

        // GM plan
        if (($plan->metadata['gm_plan'] ?? false) === true) {
            if ($user->hasGmSubscription()) {
                session()->flash('error', __('billing.error_you_already_have_a_gm_subscription'));

                return;
            }

            Log::info('User activated GM subscription from billing portal', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ]);

            // The plan the user clicked, not a re-resolved "the GM
            // plan" — with tier variants live, re-resolution could
            // activate a different row than the one on the button.
            $result = app(GmRoleService::class)->activateGmSubscription($user, $plan);

            if ($result) {
                session()->flash('success', __('billing.content_gm_subscription_activated'));
            } else {
                session()->flash('error', __('billing.error_failed_to_activate_gm_subscription'));
            }

            return;
        }

        Log::warning('Unhandled local plan activation', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);

        session()->flash('error', __('billing.error_this_plan_is_not_available_for_purchase_yet'));
    }

    public function render(): View
    {
        $user = authenticatedUser();
        $subscription = $user->subscription();
        $transactions = $user->transactions()->latest('billed_at')->take(10)->get();
        $membershipTypes = MembershipType::active()->orderBy('price_cents')->get();

        // GM (local) subscription card — active or canceled. Self-serve
        // first: it is the row this portal's cancel/reactivate actions
        // own; a comp row surfaces only for comp-only users (status
        // display without the self-serve controls).
        $gmSubscription = $user->localSubscriptions()
            ->whereHas('membershipType', fn ($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->orderByRaw('source = ? desc', [LocalSubscriptionSource::SelfServe->value])
            ->first();

        // The card's status pill reflects live BENEFIT state, not the
        // displayed row's status: a canceled self-serve row beside an
        // active comp means GM tools are still live (the
        // last-active-membership invariant), and the pill must not claim
        // "Inactive" while benefits survive.
        $gmBenefitsActive = app(GmRoleService::class)->isGmActive($user);

        return view('livewire.billing.billing-portal', [
            'subscription' => $subscription,
            'transactions' => $transactions,
            'membershipTypes' => $membershipTypes,
            'gmSubscription' => $gmSubscription,
            'gmBenefitsActive' => $gmBenefitsActive,
        ]);
    }

    private function paddleVendorDomain(): string
    {
        // Sandbox uses different domain
        if (app()->environment('local', 'testing')) {
            return 'sandbox-vendors.paddle.com';
        }

        return 'vendors.paddle.com';
    }
}
