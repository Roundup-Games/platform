<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class MembershipPage extends Component
{
    public function mount(): void
    {
        //
    }

    public function initiateCheckout(int $planId): void
    {
        $user = Auth::user();
        $plan = MembershipType::active()->findOrFail($planId);

        // Local plans (e.g. free GM subscription) bypass Paddle
        if ($plan->type === 'local') {
            $this->handleLocalSubscription($user, $plan);
            return;
        }

        if (! $plan->paddle_price_id) {
            session()->flash('error', __('billing.error_this_plan_is_not_available_for_purchase_yet'));

            return;
        }

        if ($user->subscribed()) {
            session()->flash('error', __('billing.error_you_already_have_an_active'));

            return;
        }

        Log::info('Membership checkout initiated from membership page', [
            'user_id' => $user->id,
            'membership_type_id' => $plan->id,
            'plan_name' => $plan->name,
            'paddle_price_id' => $plan->paddle_price_id,
        ]);

        $this->redirect(route('billing.checkout', ['planId' => $plan->id]));
    }

    protected function handleLocalSubscription($user, MembershipType $plan): void
    {
        // GM plan — activate via GmRoleService
        if (($plan->metadata['gm_plan'] ?? false) === true) {
            if ($user->hasGmSubscription()) {
                session()->flash('error', __('billing.error_you_already_have_a_gm_subscription'));

                return;
            }

            $gmRoleService = app(GmRoleService::class);
            $result = $gmRoleService->activateGmSubscription($user);

            if ($result) {
                session()->flash('success', __('billing.content_gm_subscription_activated'));
            } else {
                session()->flash('error', __('billing.error_failed_to_activate_gm_subscription'));
            }

            return;
        }

        // Generic local plan — not yet implemented
        Log::warning('Unhandled local subscription plan', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
        ]);

        session()->flash('error', __('billing.error_this_plan_is_not_available_for_purchase_yet'));
    }

    public function render()
    {
        $user = Auth::user();
        $subscription = $user->subscription();
        $membershipTypes = MembershipType::active()->orderBy('price_cents')->get();

        // Determine if subscription is expiring soon (within 30 days)
        $expiringSoon = false;
        $daysUntilExpiry = null;

        if ($subscription && $subscription->active()) {
            $endsAt = $subscription->ends_at;

            if ($endsAt) {
                $daysUntilExpiry = (int) round(now()->diffInDays($endsAt, false));
                $expiringSoon = $daysUntilExpiry >= 0 && $daysUntilExpiry <= 30;
            }
        }

        // Check for active GM (local) subscription
        $gmSubscription = $user->localSubscriptions()
            ->whereHas('membershipType', fn($q) => $q->whereJsonContains('metadata->gm_plan', true))
            ->active()
            ->first();

        return view('livewire.billing.membership-page', [
            'subscription' => $subscription,
            'membershipTypes' => $membershipTypes,
            'expiringSoon' => $expiringSoon,
            'daysUntilExpiry' => $daysUntilExpiry,
            'gmSubscription' => $gmSubscription,
        ]);
    }
}
