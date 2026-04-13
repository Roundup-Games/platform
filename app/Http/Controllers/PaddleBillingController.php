<?php

namespace App\Http\Controllers;

use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaddleBillingController extends Controller
{
    /**
     * Redirect to Paddle checkout for a membership subscription.
     */
    public function checkout(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $user = Auth::user();

        if (! $membershipType->paddle_price_id) {
            Log::error('Paddle checkout attempted for membership type without Paddle price ID', [
                'membership_type_id' => $membershipType->id,
                'membership_type_name' => $membershipType->name,
            ]);

            return back()->with('error', 'This membership plan is not available for purchase yet.');
        }

        Log::info('Paddle checkout initiated', [
            'user_id' => $user->id,
            'membership_type_id' => $membershipType->id,
            'paddle_price_id' => $membershipType->paddle_price_id,
        ]);

        $checkout = $user->subscribe($membershipType->paddle_price_id)
            ->returnTo(route('billing.portal'))
            ->create();

        return redirect($checkout);
    }

    /**
     * Redirect to Paddle checkout for a one-time event registration payment.
     */
    public function oneTimeCheckout(Request $request): RedirectResponse
    {
        $request->validate([
            'price_id' => 'required|string',
            'event_id' => 'nullable|integer|exists:events,id',
        ]);

        $user = Auth::user();
        $priceId = $request->input('price_id');

        Log::info('Paddle one-time checkout initiated', [
            'user_id' => $user->id,
            'paddle_price_id' => $priceId,
            'event_id' => $request->input('event_id'),
        ]);

        $checkout = $user->pay($priceId)
            ->returnTo(route('billing.portal'))
            ->create();

        return redirect($checkout);
    }
}
