<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
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

        Log::info('Subscription resumed successfully', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);

        session()->flash('success', __('billing.content_your_subscription_has_been_resumed_welcome_back'));
    }

    public function render()
    {
        $user = Auth::user();
        $subscription = $user->subscription();
        $transactions = $user->transactions()->latest('billed_at')->take(10)->get();
        $membershipTypes = MembershipType::active()->orderBy('price_cents')->get();

        return view('livewire.billing.billing-portal', [
            'subscription' => $subscription,
            'transactions' => $transactions,
            'membershipTypes' => $membershipTypes,
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
