<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class BillingPortal extends Component
{
    public ?string $portalUrl = null;

    public function mount(): void
    {
        $user = Auth::user();

        // Build the Paddle customer portal URL if the user has a Paddle ID
        if ($user->paddle_id) {
            $this->portalUrl = "https://{$this->paddleVendorDomain()}/customer/login?customer_id={$user->paddle_id}";
        }
    }

    public function cancelSubscription(): void
    {
        $user = Auth::user();
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

        // If the user also has a GM role, revoke it (Paddle subscription lapsed)
        if ($user->isGM()) {
            app(GmRoleService::class)->revokeGMRole($user);
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
        $user = Auth::user();
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
        $user = Auth::user();

        if (! $user->hasGmSubscription()) {
            session()->flash('error', __('billing.error_no_active_gm_subscription_to_cancel'));

            return;
        }

        Log::info('User initiated GM subscription cancellation', [
            'user_id' => $user->id,
        ]);

        app(GmRoleService::class)->deactivateGmSubscription($user);

        session()->flash('success', __('billing.content_gm_subscription_canceled'));
    }

    public function reactivateGmSubscription(): void
    {
        $user = Auth::user();

        // Check if they have a canceled GM subscription they can reactivate
        $canceledGmSub = $user->localSubscriptions()
            ->whereHas('membershipType', fn($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->where('status', 'canceled')
            ->first();

        if (! $canceledGmSub) {
            session()->flash('error', __('billing.error_no_gm_subscription_to_reactivate'));

            return;
        }

        Log::info('User reactivated GM subscription', [
            'user_id' => $user->id,
        ]);

        app(GmRoleService::class)->activateGmSubscription($user);

        session()->flash('success', __('billing.content_gm_subscription_reactivated'));
    }

    public function activateLocalPlan(string $planId): void
    {
        $user = Auth::user();
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

            $result = app(GmRoleService::class)->activateGmSubscription($user);

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

    public function render()
    {
        $user = Auth::user();
        $subscription = $user->subscription();
        $transactions = $user->transactions()->latest('billed_at')->take(10)->get();
        $membershipTypes = MembershipType::active()->orderBy('price_cents')->get();

        // Check for active GM (local) subscription
        $gmSubscription = $user->localSubscriptions()
            ->whereHas('membershipType', fn($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->first(); // active or canceled

        return view('livewire.billing.billing-portal', [
            'subscription' => $subscription,
            'transactions' => $transactions,
            'membershipTypes' => $membershipTypes,
            'gmSubscription' => $gmSubscription,
        ]);
    }

    private function paddleVendorDomain(): string
    {
        $vendorId = config('cashier.vendor_id', '');

        // Sandbox uses different domain
        if (app()->environment('local', 'testing')) {
            return 'sandbox-vendors.paddle.com';
        }

        return 'vendors.paddle.com';
    }
}
