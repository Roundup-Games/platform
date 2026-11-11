<?php

namespace App\Mcp\Concerns;

use App\Models\McpClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Agent-actor attribution for host write tools (M079/S03/T02).
 *
 * A write through the agent surface executes the identical domain service
 * and notification fan-out as the web action — the audit difference is
 * attribution. This concern resolves the authenticated consumer (the
 * mcp_clients row McpAuthentication admitted, re-derived from the acting
 * user's Sanctum PAT) and emits one structured event per write carrying
 * actor=agent + the consumer id, next to the actor-stamped mcp_calls row
 * the middleware already writes (tool, mcp_client_id, hashed arguments).
 * The web path emits neither: an agent write is observably distinguishable
 * in the audit trail without any service-level branching.
 */
trait MarksAgentActor
{
    /**
     * The agent consumer behind this tool call — the mcp_clients row
     * linked to the acting user's current PAT (the same linkage
     * McpAuthentication resolved at the gate), or null when the acting
     * user carries no MCP consumer linkage (never expected past the
     * gate; the marker degrades to actor+user only).
     */
    protected function agentConsumer(User $user): ?McpClient
    {
        $token = $user->currentAccessToken();

        // The vendor trait types this as PersonalAccessToken, but Sanctum's
        // guard attaches a TransientToken for session-cookie auth — a
        // runtime possibility the PHPDoc cannot see (same ignore as
        // McpAuthentication).
        if (! $token instanceof PersonalAccessToken) { // @phpstan-ignore instanceof.alwaysTrue
            return null;
        }

        /** @var McpClient|null $client */
        $client = McpClient::query()
            ->where('personal_access_token_id', $token->getKey())
            ->first();

        return $client;
    }

    /**
     * The agent-actor marker event every host write tool emits:
     * actor=agent, the consumer id, the acting user, and entity
     * identifiers. Context carries ids and lengths only — never free-text
     * write bodies (the audit trail holds argument hashes, and the log
     * must not widen that surface).
     *
     * @param  array<string, mixed>  $context
     */
    protected function logAgentWrite(string $action, User $user, array $context = []): void
    {
        Log::info('mcp.agent_write', array_filter([
            'actor' => 'agent',
            'action' => $action,
            'consumer_id' => $this->agentConsumer($user)?->id,
            'user_id' => $user->id,
            ...$context,
        ], fn (mixed $value): bool => $value !== null));
    }
}
