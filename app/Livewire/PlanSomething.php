<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Unified "Plan something" entry point.
 *
 * A single 2-button page that asks the host one question — one-time session
 * or recurring event — then redirects to the appropriate existing create
 * page. The underlying models (Game vs Campaign), routes, and clone/pre-fill
 * behaviour are unchanged; this component only adds a decision funnel.
 *
 * Route: /plan  (name: plan.create)
 */
#[Layout('layouts.app')]
class PlanSomething extends Component
{
    /**
     * Redirect to one-shot game creation with the chosen type pre-selected.
     *
     * The type is passed via the ?type= query param, which CreateGame::mount()
     * consumes to skip the type-selector step and apply smart defaults.
     */
    public function planOneShot(string $type = 'board_game'): void
    {
        $this->redirect(route('games.create', ['type' => $type]), navigate: true);
    }

    /**
     * Redirect to recurring campaign creation.
     *
     * CreateCampaign::mount() applies smart defaults from the user's prior
     * campaigns; no type param needed (campaigns have their own game_type
     * picker that defaults from history).
     */
    public function planRecurring(): void
    {
        $this->redirect(route('campaigns.create'), navigate: true);
    }

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
