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

    #[Validate('nullable|string|exists:events,id')]
    public ?string $eventId = null;

    #[Validate('nullable|string')]
    public ?string $eventPriceId = null;

    public string $mode = 'subscription'; // subscription or one-time

    public function mount(?int $planId = null, ?string $priceId = null, ?string $eventId = null): void
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
            session()->flash('error', __('billing.error_this_membership_plan_is_not'));

            return;
        }

        if ($user->subscribed()) {
            session()->flash('error', __('billing.error_you_already_have_an_active_subscription'));

            return;
        }

        Log::info('Paddle subscription checkout initiated', [
            'user_id' => $user->id,
            'membership_type_id' => $plan->id,
            'paddle_price_id' => $plan->paddle_price_id,
        ]);

        $checkoutOptions = $user->subscribe($plan->paddle_price_id)
            ->returnTo(route('billing.portal'))
            ->options();

        $this->dispatch('open-paddle-checkout', options: $checkoutOptions);
    }

    private function initOneTimeCheckout($user): void
    {
        // One-time payments must be tied to an event — reject if no eventId
        if (! $this->eventId) {
            Log::warning('Paddle one-time checkout rejected: no event_id provided', [
                'user_id' => $user->id,
                'provided_price_id' => $this->eventPriceId,
            ]);

            session()->flash('error', __('billing.error_a_valid_event_is_required_for_payment'));

            return;
        }

        // Load event and cross-check price_id
        $event = \App\Models\Event::findOrFail($this->eventId);
        $expectedPriceId = $event->metadata['paddle_price_id'] ?? null;

        if (! $expectedPriceId) {
            Log::warning('Paddle one-time checkout rejected: event has no paddle_price_id configured', [
                'user_id' => $user->id,
                'event_id' => $this->eventId,
                'provided_price_id' => $this->eventPriceId,
            ]);

            session()->flash('error', __('billing.error_this_event_does_not_have_a_payment_configuration'));

            return;
        }

        if ($this->eventPriceId !== $expectedPriceId) {
            Log::warning('Paddle one-time checkout rejected: price_id mismatch', [
                'user_id' => $user->id,
                'event_id' => $this->eventId,
                'provided_price_id' => $this->eventPriceId,
                'expected_price_id' => $expectedPriceId,
            ]);

            session()->flash('error', __('billing.error_invalid_payment_option_selected'));

            return;
        }

        Log::info('Paddle one-time checkout initiated', [
            'user_id' => $user->id,
            'paddle_price_id' => $this->eventPriceId,
            'event_id' => $this->eventId,
        ]);

        $checkoutOptions = $user->checkout($this->eventPriceId)
            ->customData(['event_id' => $this->eventId])
            ->returnTo(route('billing.portal'))
            ->options();

        $this->dispatch('open-paddle-checkout', options: $checkoutOptions);
    }

    public function render()
    {
        return view('livewire.billing.checkout');
    }
}
