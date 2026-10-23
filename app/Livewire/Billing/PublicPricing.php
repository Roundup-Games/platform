<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Public, guest-visible pricing page.
 *
 * Reads the same MembershipType::active() source as the authenticated
 * /membership page — no second tier list to drift out of sync — but
 * lists only purchasable (Paddle) plans: /pricing advertises what money
 * buys, while free local tiers such as the Game Master plan are
 * activated on /membership, not marketed here. Shows the honest
 * free/paid boundary (joining and playing is free; membership supports
 * the platform) and routes guests to signup, members to /membership
 * for checkout.
 */
#[Layout('components.public-layout')]
class PublicPricing extends Component
{
    public function render(): View
    {
        // Explicit per-locale SEO (R072): without this the page falls back
        // to the URL-inferred title ("Pricing") — the demand-matched,
        // honest framing lives in the lang keys.
        seo(new SEOData(
            title: __('billing.seo_title_pricing'),
            description: __('billing.seo_description_pricing'),
        ));

        return view('livewire.billing.public-pricing', [
            // Same source and ordering contract as MembershipPage::render(),
            // minus non-advertisable tiers (see class docblock): /pricing
            // shows only plans a visitor can actually buy — paddle plans
            // WITH a configured price id. A paddle plan without a price id
            // is pre-launch (e.g. the GM tiers before PADDLE_GM_* are set)
            // and stays invisible; /membership remains the activation
            // surface for free local plans.
            'membershipTypes' => MembershipType::active()
                ->where('type', 'paddle')
                ->where('paddle_price_id', '!=', '')
                ->orderBy('price_cents')
                ->get(),
        ]);
    }
}
