<?php

namespace App\Dto\Dashboard;

use App\Services\DashboardAssembler;

/**
 * The full Dashboard for one viewer, ready to render.
 *
 * Built exclusively by the Dashboard assembler. Exactly one of `$newcomer` / `$established`
 * is non-null, determined by `$shared->mode`. The inactive wing is null — no stub props —
 * which is what lets Phase 3 branch the Blade on `mode` and delete the stub symmetry.
 *
 * Phase 1 keeps the Blade untouched via {@see toViewProps()}, which projects the typed
 * view-model back into the flat 26-key dictionary `livewire.dashboard` consumes today,
 * emitting the legacy stub values for the inactive mode's keys. `toViewProps()` is deleted
 * in Phase 3 once the Blade reads typed props off the view-model directly.
 *
 * @see DashboardAssembler
 */
final class AssembledDashboard
{
    public function __construct(
        public readonly string $mode,
        public readonly SharedDashboard $shared,
        public readonly ?NewcomerDashboard $newcomer = null,
        public readonly ?EstablishedDashboard $established = null,
    ) {}

    public function isNewcomer(): bool
    {
        return $this->mode === 'newcomer';
    }

    public function isEstablished(): bool
    {
        return $this->mode === 'established';
    }

    /**
     * Convenience accessor: the active newcomer wing, throwing if inactive.
     */
    public function newcomer(): NewcomerDashboard
    {
        return $this->newcomer ?? throw new \LogicException('Dashboard is not in newcomer mode.');
    }

    /**
     * Convenience accessor: the active established wing, throwing if inactive.
     */
    public function established(): EstablishedDashboard
    {
        return $this->established ?? throw new \LogicException('Dashboard is not in established mode.');
    }
}
