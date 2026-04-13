<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class Checkout extends Component
{
    public ?int $membershipTypeId = null;

    public ?MembershipType $membershipType = null;

    #[Validate('nullable|integer|exists:events,id')]
    public ?int $eventId = null;

    #[Validate('nullable|string')]
    public ?string $eventPriceId = null;

    public string $mode = 'subscription'; // subscription or one-time

    public function mount(?int $planId = null, ?string $priceId = null, ?int $eventId = null): void
    {
        if ($planId) {
            $this->membershipTypeId = $planId;
            $this->membershipType = MembershipType::active()->findOrFail($planId);
            $this->mode = 'subscription';
        } elseif ($priceId) {
            $this->eventPriceId = $priceId;
            $this->eventId = $eventId;
            $this->mode = 'one-time';
        } else {
            abort(400, 'Either a membership type or price ID is required.');
        }
    }

    public function checkout(): void
    {
        $this->validate();

        $user = Auth::user();

        if ($this->mode === 'subscription') {
            $this->initSubscriptionCheckout($user);
        } else {
            $this->initOneTimeCheckout($user);
        }
    }

    private function initSubscriptionCheckout($user): void
    {
        // Refresh from DB in case it was set via mount
        $plan = $this->membershipType ?? MembershipType::active()->find($this->membershipTypeId);

        if (! $plan?->paddle_price_id) {
            session()->flash('error', 'This membership plan is not available for purchase yet.');

            return;
        }

        if ($user->subscribed()) {
            session()->flash('error', 'You already have an active subscription.');

            return;
        }

        Log::info('Paddle subscription checkout initiated', [
            'user_id' => $user->id,
            'membership_type_id' => $plan->id,
            'paddle_price_id' => $plan->paddle_price_id,
        ]);

        $checkout = $user->subscribe($plan->paddle_price_id)
            ->returnTo(route('billing.portal'))
            ->create();

        $this->redirect($checkout);
    }

    private function initOneTimeCheckout($user): void
    {
        Log::info('Paddle one-time checkout initiated', [
            'user_id' => $user->id,
            'paddle_price_id' => $this->eventPriceId,
            'event_id' => $this->eventId,
        ]);

        $checkout = $user->pay($this->eventPriceId)
            ->returnTo(route('billing.portal'))
            ->create();

        $this->redirect($checkout);
    }

    public function render()
    {
        return view('livewire.billing.checkout');
    }
}
