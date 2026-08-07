<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Unified "Plan something" entry point.
 *
 * A single 2-link page that asks the host one question — one-time session
 * or recurring event — then links straight to the appropriate create page.
 * The cards are plain `<a wire:navigate>` anchors (no Livewire round-trip),
 * so navigation is instant and reliable across the wire:navigate surface used
 * by the rest of the app.
 *
 * Route: /plan  (name: plan.create)
 */
#[Layout('layouts.app')]
class PlanSomething extends Component
{
    public function render(): View
    {
        seo(new SEOData(
            title: __('plan.seo_title'),
            description: __('plan.seo_description'),
            robots: 'noindex, nofollow',
        ));

        return view('livewire.plan-something');
    }
}
