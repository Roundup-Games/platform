<?php

namespace App\Mcp\Support;

/**
 * The request-scoped ops audit target (M079/S04/T01, R090).
 *
 * An ops tool that resolves a specific target entity (a ticket, a member)
 * records it here during dispatch; McpAuthentication drains it into the
 * mcp_calls audit row after the message completes. That linkage —
 * consumer + acting admin + target entity — is the trail that replaces
 * ad-hoc SSH/artisan ops access: every targeted ops call answers "which
 * consumer, acting as which admin, touched which record".
 *
 * Registered as a scoped binding (AppServiceProvider) and cleared by the
 * middleware at the start of every request, so state can never leak
 * across requests even under long-lived workers.
 */
class McpAuditTarget
{
    public ?string $type = null;

    public ?string $id = null;

    /**
     * Record the entity this call operates on. Ids are stringified
     * (user ids are UUIDs, Escalated ticket PKs are ints).
     */
    public function set(string $type, int|string $id): void
    {
        $this->type = $type;
        $this->id = (string) $id;
    }

    /**
     * True when a target has been recorded for this dispatch.
     */
    public function filled(): bool
    {
        return $this->type !== null && $this->id !== null;
    }

    /**
     * Reset for the next dispatch (the middleware clears before auth and
     * drains after audit).
     */
    public function clear(): void
    {
        $this->type = null;
        $this->id = null;
    }
}
