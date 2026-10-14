<?php

namespace App\Livewire\Billing;

use App\Models\MembershipType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public, guest-visible pricing page.
 *
 * Reads the same MembershipType::active() source as the authenticated
 * /membership page — no second tier list to drift out of sync. Shows the
 * honest free/paid boundary (joining and playing is free; membership
 * supports the platform, the free Game Master tier unlocks the GM
 * workspace) and routes guests to signup, members to /membership for
 * checkout.
 */
#[Layout('components.public-layout')]
class PublicPricing extends Component
{
    public function render(): View
    {
        return view('livewire.billing.public-pricing', [
            // Identical query to MembershipPage::render() — the single
            // ordering contract for tier cards everywhere.
            'membershipTypes' => MembershipType::active()
                ->orderBy('price_cents')
                ->get(),
        ]);
    }
}
