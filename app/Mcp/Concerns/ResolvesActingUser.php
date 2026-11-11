<?php

namespace App\Mcp\Concerns;

use App\Models\User;
use Laravel\Mcp\Request;

/**
 * Resolves the acting user for a participant-scoped MCP tool (M079/S02).
 *
 * Every participant tool answers "for THIS user" — never "for whoever".
 * The surface authenticates through the Sanctum guard (bearer PAT linked
 * to an mcp_clients row, resolved by McpAuthentication before dispatch),
 * so the tool resolves the same guard. The null return is a defense-in-
 * depth branch only: the gate already rejected unauthenticated calls, but
 * a tool that needs a principal fails closed instead of assuming one.
 */
trait ResolvesActingUser
{
    /**
     * The user acting through this tool call, or null when the guard
     * cannot resolve one (never expected past McpAuthentication).
     */
    protected function actingUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }
}
