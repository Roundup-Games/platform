<?php

namespace App\Livewire;

use App\Services\DashboardAssembler;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * Render the authenticated user's Dashboard.
     *
     * Assembly (mode resolution, section reads, feed blending, quick-action derivation)
     * lives in the Dashboard deep module — {@see DashboardAssembler}. This component is
     * now a thin view-binding adapter: resolve the user, assemble, project to the view
     * contract. See ADR-0001 for the interface decision and the phased migration.
     */
    public function render(): View
    {
        $dashboard = app(DashboardAssembler::class)->assemble(authenticatedUser());

        return view(
            'livewire.dashboard',
            ['dashboard' => $dashboard] + $dashboard->toViewProps(),
        );
    }
}
