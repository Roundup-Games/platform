<?php

use App\Mcp\Tools\PlatformInfoTool;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Traits\InteractsWithMcp;

/*
|--------------------------------------------------------------------------
| MCP Transport (M079/S01)
|--------------------------------------------------------------------------
|
| Exercises the real HTTP surface an agent talks to: POST /mcp with the
| 2026-07-28 protocol (matching Mcp-* headers + _meta), through the
| package's Origin/header validation middleware and our auth gate, down
| to hand-registered tools. The unauthenticated 401, the CSRF-free agent
| POST (no token, no Origin header), and the structured transport events
| are all pinned here. Authentication is the D277 shape (T02): a Sanctum
| PAT linked to an mcp_clients row, sent as a bearer credential.
|
*/

uses(InteractsWithMcp::class);

// ── Auth gate ────────────────────────────────────────────

test('unauthenticated agent requests are rejected with 401 json, never a 500 or redirect', function () {
    $response = $this->postJson('/mcp', $this->mcpMessage('server/discover'), $this->mcpHeaders('server/discover'));

    $response->assertStatus(401)
        ->assertJsonPath('error.reason', 'unauthenticated')
        ->assertJson(['error' => ['message' => 'Unauthenticated.']])
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

test('agent posts carry no csrf token or origin header and are not rejected', function () {
    // An agent cannot mint CSRF tokens (no browser session) and sends no
    // Origin header. A 419 would mean CSRF applied to this surface; a 403
    // would mean Origin validation rejected the origin-less request.
    // Either would break every non-browser MCP client.
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());
    unset($client);

    $response = $this->postMcp($bearer, 'server/discover');

    expect($response->getStatusCode())->not->toBeIn([419, 403])
        ->and($response->getStatusCode())->toBe(200);
});

// ── Handshake & tools ────────────────────────────────────

test('authenticated handshake discovers the roundup server', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    $response = $this->postMcp($bearer, 'server/discover');

    $response->assertStatus(200)
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('result.supportedVersions.0', '2026-07-28')
        ->assertJsonStructure([
            'result' => ['supportedVersions', 'capabilities' => ['tools'], 'instructions'],
        ]);
});

test('tools list exposes the platform info tool', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    $response = $this->postMcp($bearer, 'tools/list');

    $response->assertStatus(200);

    $toolNames = collect($response->json('result.tools'))->pluck('name');

    expect($toolNames)->toContain(PlatformInfoTool::NAME);

    $tool = collect($response->json('result.tools'))->firstWhere('name', PlatformInfoTool::NAME);

    expect($tool['description'])->toBeString()->not->toBe('')
        ->and($tool['inputSchema'])->toBeArray();
})->group('smoke');

test('platform info tool returns the app name, url and locale', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME);

    $response->assertStatus(200)
        ->assertJsonPath('result.isError', false);

    $payload = json_decode((string) $response->json('result.content.0.text'), true);

    expect($payload)->toBeArray()
        ->and($payload['name'])->toBe(config('app.name'))
        ->and($payload['url'])->toBe(config('app.url'))
        ->and($payload['locale'])->toBe(app()->getLocale());
});

// ── Observability ────────────────────────────────────────

test('mcp connect and call structured events fire on the default channel', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    Log::shouldReceive('debug')->once()->with('mcp.connect', Mockery::on(
        fn (array $context): bool => ($context['protocol_version'] ?? null) === '2026-07-28'
            && isset($context['duration_ms']) && is_float($context['duration_ms'])
    ));

    Log::shouldReceive('debug')->once()->with('mcp.call', Mockery::on(
        fn (array $context): bool => ($context['tool'] ?? null) === PlatformInfoTool::NAME
            && isset($context['duration_ms']) && is_float($context['duration_ms'])
    ));

    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->postMcp($bearer, 'server/discover')->assertStatus(200);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);
});
