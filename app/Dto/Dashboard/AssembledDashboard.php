<?php

namespace App\Dto\Dashboard;

use App\Services\DashboardAssembler;

/**
 * The full Dashboard for one viewer, ready to render.
 *
 * Built exclusively by the Dashboard assembler. Exactly one of `$newcomer` / `$established`
 * is non-null, determined by `$shared->mode`. The inactive wing is null — no stub props.
 *
 * The Blade receives this typed view-model as `$dashboard`, branches on `mode`, and
 * unpacks the active wing's properties into the flat variables each partial consumes.
 *
 * @see DashboardAssembler
 * @see docs/adr/0001-dashboard-cache-section-registry.md
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
