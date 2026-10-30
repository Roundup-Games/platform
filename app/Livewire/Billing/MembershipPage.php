<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Illuminate\Contracts\View\View;
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

    public function initiateCheckout(string $planId): void
    {
        $user = authenticatedUser();
        $plan = MembershipType::active()->findOrFail($planId);

        // Local plans (e.g. free GM subscription) bypass Paddle
        if ($plan->type === 'local') {
            $this->handleLocalSubscription($user, $plan);

            return;
        }

        if ($plan->paddle_price_id === null || $plan->paddle_price_id === '') {
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

    protected function handleLocalSubscription(User $user, MembershipType $plan): void
    {
        // Legacy free GM tier (D241): closed to new self-serve
        // activations — the listing already hides it; this guard makes
        // the closure independent of the listing (direct plan-id
        // probes cannot activate either). Comp grants and grandfathered
        // subscriptions use their own paths and never pass through
        // here.
        if (($plan->metadata['legacy_free'] ?? false) === true) {
            session()->flash('error', __('billing.error_this_plan_is_not_available_for_purchase_yet'));

            return;
        }

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

    public function render(): View
    {
        $user = authenticatedUser();
        $subscription = $user->subscription();
        // The self-serve listing shows only purchasable tiers: paddle
        // plans need a configured price id (pre-launch GM tiers stay
        // hidden until PADDLE_GM_* are set), and legacy_free local plans
        // (the retired free GM tier) are closed to new activations —
        // comp grants and existing grandfathered subscriptions resolve
        // those rows directly and never read this listing. Note the
        // orWhereNull: on Postgres, whereJsonDoesntContain ALSO excludes
        // rows whose JSON path is missing entirely — plans that simply
        // predate the legacy_free flag must stay listed.
        $membershipTypes = MembershipType::active()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('type', 'paddle')->where('paddle_price_id', '!=', ''))
                ->orWhere(fn ($q) => $q->where('type', 'local')->where(fn ($qq) => $qq
                    ->whereJsonDoesntContain('metadata->legacy_free', true)
                    ->orWhereNull('metadata->legacy_free'))))
            ->orderBy('price_cents')
            ->get();

        // Determine if subscription is expiring soon (within 30 days)
        $expiringSoon = false;
        $daysUntilExpiry = null;

        if ($subscription !== null && $subscription->active()) {
            $endsAt = $subscription->ends_at;

            if ($endsAt !== null) {
                $daysUntilExpiry = (int) round(now()->diffInDays($endsAt, false));
                $expiringSoon = $daysUntilExpiry >= 0 && $daysUntilExpiry <= 30;
            }
        }

        // Check for active GM (local) subscription
        $gmSubscription = $user->localSubscriptions()
            ->whereHas('membershipType', fn ($q) => $q->whereJsonContains('metadata->gm_plan', true))
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
