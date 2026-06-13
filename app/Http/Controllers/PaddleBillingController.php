<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaddleBillingController extends Controller
{
    /**
     * Redirect to Paddle checkout for a membership subscription.
     * Cashier Paddle uses client-side Paddle.js — we build the Checkout
     * options server-side and pass them to the checkout page for rendering.
     */
    public function checkout(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $user = authenticatedUser();

        if (! $membershipType->paddle_price_id) {
            Log::error('Paddle checkout attempted for membership type without Paddle price ID', [
                'membership_type_id' => $membershipType->id,
                'membership_type_name' => $membershipType->name,
            ]);

            return back()->with('error', __('billing.error_this_membership_plan_is_not'));
        }

        Log::info('Paddle checkout initiated', [
            'user_id' => $user->id,
            'membership_type_id' => $membershipType->id,
            'paddle_price_id' => $membershipType->paddle_price_id,
        ]);

        $checkout = $user->subscribe($membershipType->paddle_price_id)
            ->returnTo(route('billing.portal'));

        return redirect()->route('billing.checkout', ['planId' => $membershipType->id]);
    }

    /**
     * Redirect to Paddle checkout for a one-time event registration payment.
     * Cashier Paddle uses client-side Paddle.js — we build the Checkout
     * options server-side and pass them to the checkout page for rendering.
     */
    public function oneTimeCheckout(Request $request): RedirectResponse
    {
        $request->validate([
            'price_id' => 'required|string',
            'event_id' => 'required|string|uuid|exists:events,id',
        ]);

        $user = authenticatedUser();
        $priceId = $request->input('price_id');
        $eventId = $request->input('event_id');

        // Load the event and cross-check the price_id
        $event = Event::findOrFail($eventId);
        $expectedPriceId = $event->metadata['paddle_price_id'] ?? null;

        if (! $expectedPriceId) {
            Log::warning('Paddle one-time checkout rejected: event has no paddle_price_id configured', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'provided_price_id' => $priceId,
            ]);

            return back()->with('error', __('billing.error_this_event_does_not_have_a_payment_configuration'));
        }

        if ($priceId !== $expectedPriceId) {
            Log::warning('Paddle one-time checkout rejected: price_id mismatch', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'provided_price_id' => $priceId,
                'expected_price_id' => $expectedPriceId,
            ]);

            return back()->with('error', __('billing.error_invalid_payment_option_selected'));
        }

        Log::info('Paddle one-time checkout initiated', [
            'user_id' => $user->id,
            'paddle_price_id' => $priceId,
            'event_id' => $eventId,
        ]);

        return redirect()->route('billing.checkout', [
            'priceId' => $priceId,
            'eventId' => $eventId,
        ]);
    }
}
