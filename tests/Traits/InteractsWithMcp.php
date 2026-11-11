<?php

namespace Tests\Traits;

use App\Enums\McpAbility;
use App\Models\McpClient;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Build MCP JSON-RPC messages and authenticate as an agent consumer
 * (M079/S01). Mirrors the wire format the v1.2.0 transport expects and
 * issues the D277 credential shape: one mcp_clients row linked to the
 * Sanctum personal access token that carries the abilities.
 */
trait InteractsWithMcp
{
    /**
     * Build a valid 2026-07-28 MCP JSON-RPC message. $params must be an
     * object-shaped array; the protocol _meta is merged in automatically.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function mcpMessage(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => random_int(1, PHP_INT_MAX),
            'method' => $method,
            'params' => $params + [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ];
    }

    /**
     * The headers the v1.2.0 ValidateMcpHeaders middleware requires to
     * match the request body. No Origin header: non-browser agent clients
     * don't send one, and that must keep passing the Origin validation.
     *
     * @return array<string, string>
     */
    protected function mcpHeaders(string $method, ?string $toolName = null): array
    {
        return array_filter([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
            'Mcp-Name' => $toolName,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Issue a consumer credential for $user: one mcp_clients row linked to
     * a Sanctum PAT carrying $abilities (the D277 issuance shape minus the
     * Livewire surface, for direct middleware-level tests).
     *
     * @param  list<string>  $abilities
     * @return array{0: McpClient, 1: string} [client, bearer plaintext]
     */
    protected function mcpConsumer(User $user, array $abilities = [McpAbility::Read->value]): array
    {
        $newAccessToken = $user->createToken('test agent', $abilities);

        $client = $user->mcpClients()->create([
            'name' => 'Test agent',
            'personal_access_token_id' => $newAccessToken->accessToken->id,
        ]);

        return [$client, $newAccessToken->plainTextToken];
    }

    /**
     * Issue a consumer credential whose owner holds the Game Master role
     * — the host surface's dispatch precondition (R097/D278: mcp:host is
     * dual-gated with the Game Master role, re-checked per dispatch).
     * Host-tool suites use this so their assertions exercise per-entity
     * authorization inside the tools, not the gate.
     *
     * @param  list<string>  $abilities
     * @return array{0: McpClient, 1: string} [client, bearer plaintext]
     */
    protected function mcpGmConsumer(User $user, array $abilities = [McpAbility::Host->value]): array
    {
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $user->assignRole('Game Master');

        return $this->mcpConsumer($user, $abilities);
    }

    /**
     * Ensure the Game Master role exists in the test baseline (it is not
     * part of the schema dump's seeded roles) — call before assignRole for
     * tests that manage the role manually (dispatch dual-gate lifecycle).
     */
    protected function ensureGameMasterRoleExists(): void
    {
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    /**
     * POST /mcp as an authenticated agent consumer (bearer PAT).
     *
     * @param  array<string, mixed>  $params
     */
    protected function postMcp(string $bearer, string $method, array $params = [], ?string $toolName = null): TestResponse
    {
        return $this->postJson(
            '/mcp',
            $this->mcpMessage($method, $params),
            $this->mcpHeaders($method, $toolName) + ['Authorization' => 'Bearer '.$bearer],
        );
    }
}
