<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
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

        return view('livewire.billing.membership-page', [
            'subscription' => $subscription,
            'membershipTypes' => $membershipTypes,
            'expiringSoon' => $expiringSoon,
            'daysUntilExpiry' => $daysUntilExpiry,
        ]);
    }
}
