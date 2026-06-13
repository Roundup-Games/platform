<?php

namespace App\Livewire\Discovery;

use App\Models\Campaign;
use App\Models\Game;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class DiscoveryPortal extends Component
{
    public function render(): View
    {
        seo(new SEOData(
            title: __('discovery.seo_title_discover'),
            description: __('discovery.seo_description_discover'),
        ));

        $boardGameCount = Game::where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->visibleTo(null)
            ->whereHas('gameSystem', fn ($q) => $q->where('type', 'boardgame'))
            ->count();

        $adventureCount = Campaign::where('status', 'active')
            ->visibleTo(null)
            ->count()
            + Game::where('status', 'scheduled')
                ->where('date_time', '>', now())
                ->visibleTo(null)
                ->whereHas('gameSystem', fn ($q) => $q->where('type', 'ttrpg'))
                ->count();

        return view('livewire.discovery.discovery-portal', [
            'boardGameCount' => $boardGameCount,
            'adventureCount' => $adventureCount,
        ]);
    }
}
