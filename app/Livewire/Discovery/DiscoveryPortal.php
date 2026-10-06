<?php

namespace App\Livewire\Discovery;

use App\Models\Campaign;
use App\Models\Game;
use App\Services\CityDirectoryService;
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
            title: __('discovery.action_discover'),
            description: __('discovery.seo_description_discover'),
        ));

        $boardGameCount = Game::where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->visibleTo(null)
            ->whereHas('gameSystems', fn ($q) => $q->where('type', 'boardgame'))
            ->count();

        $adventureCount = Campaign::where('status', 'active')
            ->visibleTo(null)
            ->count()
            + Game::where('status', 'scheduled')
                ->where('date_time', '>', now())
                ->visibleTo(null)
                ->whereHas('gameSystems', fn ($q) => $q->where('type', 'ttrpg'))
                ->count();

        return view('livewire.discovery.discovery-portal', [
            'boardGameCount' => $boardGameCount,
            'adventureCount' => $adventureCount,
            // Uncached like the two counts above; each per-city resolution
            // rides its own summary cache (62-04 T06).
            'featuredCities' => app(CityDirectoryService::class)->featuredCities(),
        ]);
    }
}
