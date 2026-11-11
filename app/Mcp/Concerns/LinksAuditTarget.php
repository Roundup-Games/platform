<?php

namespace App\Mcp\Concerns;

use App\Mcp\Support\McpAuditTarget;

/**
 * Audit-target linkage for ops tools (M079/S04/T01, R090).
 *
 * An ops tool adopting this concern links the entity it operated on into
 * the mcp_calls audit row: linkAuditTarget('ticket', $ticket->id) before
 * acting, and McpAuthentication stamps target_type/target_id on the row
 * it writes after dispatch — the replacement trail for the SSH-note-free
 * ad-hoc ops access this surface retires.
 */
trait LinksAuditTarget
{
    /**
     * Link the entity this call operates on to the audit row the
     * middleware writes for this dispatch.
     */
    protected function linkAuditTarget(string $type, int|string $id): void
    {
        app(McpAuditTarget::class)->set($type, $id);
    }
}
