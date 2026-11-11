<?php

use App\Filament\Pages\System\McpUsagePage;
use App\Mcp\Tools\MySessionsTool;
use App\Mcp\Tools\PlatformInfoTool;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\Traits\InteractsWithMcp;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| MCP Usage Telemetry (M079/S05/T01)
|--------------------------------------------------------------------------
|
| Pins the admin observability surface over the agent audit trail: the
| /admin/mcp-usage Filament report aggregates mcp_calls (the S01 append-
| only audit table) into per-consumer counts, per-tool breakdown, and the
| consumer × tool matrix — answering "which consumer called which tool
| and how often" — from one grouped Eloquent aggregation with no new
| tables. Coverage mirrors the sibling System-page suites
| (QueueStructurePageTest / SearchPerformanceAiSurfacesTest): rendering
// for admins with real dispatched calls (the real bearer → middleware →
// dispatch path writes the audit rows, not factory fabrication), window
// filtering, revocation-aware history, the empty state, and access
// control. Admin blade copy is asserted literally — EN-only admin
// surface, so the K004 locale-decoupling rule does not apply.
*/

uses(InteractsWithMcp::class);

/**
 * Act as a Platform Admin on the admin panel — the only role the page
 * renders for (canAccess() -> isAdmin()).
 */
function mcpUsageAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');

    actingAs($admin);
    Filament::setCurrentPanel('admin');

    return $admin;
}

// ── Aggregation from the real dispatch surface ───────────

test('aggregates per-consumer counts and the per-tool breakdown from dispatched calls', function () {
    [$alpha, $alphaBearer] = $this->mcpConsumer(User::factory()->create());
    [$beta, $betaBearer] = $this->mcpConsumer(User::factory()->create());

    $alpha->forceFill(['name' => 'Alpha agent'])->save();
    $beta->forceFill(['name' => 'Beta agent'])->save();

    // Real dispatched calls through the actual gate: audit rows are the
    // middleware's own writes, not fabricated ones.
    $this->postMcp($alphaBearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    $this->postMcp($alphaBearer, 'tools/call', [
        'name' => MySessionsTool::NAME,
        'arguments' => (object) [],
    ], MySessionsTool::NAME)->assertStatus(200);

    // Reset the harness auth state so the next request re-resolves the
    // second bearer (the guard caches its user for the whole process).
    auth()->forgetGuards();

    $this->postMcp($betaBearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    mcpUsageAdmin();

    $page = Livewire\Livewire::test(McpUsagePage::class);

    $page->assertOk()
        ->assertSeeText('Alpha agent')
        ->assertSeeText('Beta agent')
        ->assertSeeText(PlatformInfoTool::NAME)
        ->assertSeeText(MySessionsTool::NAME)
        ->assertSeeText('mcp:read');

    $data = $page->instance()->getViewData();

    expect($data['totals']['window_calls'])->toBe(3)
        ->and($data['totals']['active_consumers'])->toBe(2)
        ->and($data['totals']['distinct_tools'])->toBe(2)
        ->and($data['totals']['registered_consumers'])->toBe(2)
        ->and($data['totals']['all_time_calls'])->toBe(3);

    $consumers = collect($data['consumers'])->keyBy('name');

    // Per-consumer isolation: Alpha's two calls never leak into Beta's row.
    expect($consumers['Alpha agent']['calls'])->toBe(2)
        ->and($consumers['Alpha agent']['tools'])->toBe(2)
        ->and($consumers['Alpha agent']['avg_latency_ms'])->toBeInt()
        ->and($consumers['Alpha agent']['last_call'])->not->toBeNull()
        ->and($consumers['Alpha agent']['revoked'])->toBeFalse()
        ->and($consumers['Beta agent']['calls'])->toBe(1)
        ->and($consumers['Beta agent']['tools'])->toBe(1);

    $tools = collect($data['tools'])->keyBy('tool');

    expect($tools[PlatformInfoTool::NAME]['calls'])->toBe(3 - 1)
        ->and($tools[PlatformInfoTool::NAME]['consumers'])->toBe(2)
        ->and($tools[MySessionsTool::NAME]['calls'])->toBe(1)
        ->and($tools[MySessionsTool::NAME]['consumers'])->toBe(1);

    // The consumer × tool matrix: exactly the (Alpha, platform.info),
    // (Alpha, my.sessions), (Beta, platform.info) pairs.
    $pairs = collect($data['pairs']);

    expect($pairs->count())->toBe(3)
        ->and($pairs->where('consumer', 'Alpha agent')->pluck('tool')->sort()->values()->all())
        ->toBe([MySessionsTool::NAME, PlatformInfoTool::NAME]);
});

test('excludes calls outside the seven-day window but keeps them in the all-time total', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    // A pre-window audit row: outside the report window, still in the table.
    $client->calls()->create([
        'user_id' => $client->user_id,
        'tool' => 'games.search',
        'actor' => 'agent',
        'arguments_hash' => hash('sha256', '{}'),
        'latency_ms' => 15,
        'created_at' => now()->subDays(8),
    ]);

    mcpUsageAdmin();

    $data = Livewire\Livewire::test(McpUsagePage::class)
        ->instance()
        ->getViewData();

    expect($data['totals']['window_calls'])->toBe(1)
        ->and($data['totals']['all_time_calls'])->toBe(2)
        ->and(collect($data['tools'])->pluck('tool'))->not->toContain('games.search')
        ->and(collect($data['consumers'])->first()['calls'])->toBe(1);
});

test('an audit row for a tool the registry no longer carries shows the unregistered marker', function () {
    [$client] = $this->mcpConsumer(User::factory()->create());

    $client->calls()->create([
        'user_id' => $client->user_id,
        'tool' => 'legacy.retired-tool',
        'actor' => 'agent',
        'arguments_hash' => hash('sha256', '{}'),
        'latency_ms' => 12,
        'created_at' => now(),
    ]);

    mcpUsageAdmin();

    $data = Livewire\Livewire::test(McpUsagePage::class)
        ->assertOk()
        ->assertSeeText('(unregistered)')
        ->instance()
        ->getViewData();

    $tool = collect($data['tools'])->firstWhere('tool', 'legacy.retired-tool');

    expect($tool['scope'])->toBe('(unregistered)');
});

test('a revoked consumer stays listed with its history so the trail outlives the credential', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $client->forceFill(['name' => 'Retired agent'])->save();

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    $client->revoke();

    mcpUsageAdmin();

    $data = Livewire\Livewire::test(McpUsagePage::class)
        ->assertOk()
        ->assertSeeText('Retired agent')
        ->assertSeeText('revoked')
        ->instance()
        ->getViewData();

    $consumer = collect($data['consumers'])->first();

    expect($consumer['name'])->toBe('Retired agent')
        ->and($consumer['revoked'])->toBeTrue()
        ->and($consumer['calls'])->toBe(1);
});

test('renders the empty state before any tool call has been audited', function () {
    mcpUsageAdmin();

    Livewire\Livewire::test(McpUsagePage::class)
        ->assertOk()
        ->assertSeeText('No MCP tool calls recorded')
        ->assertSeeText('docs/mcp-tools.md')
        ->assertDontSee('Consumer × tool');
});

// ── Access control ───────────────────────────────────────

test('the usage report is admin-only', function () {
    actingAs(User::factory()->create());

    // Route stays registered; canAccess() === false yields 403 for
    // authenticated non-admins (MEM1252).
    get('/admin/mcp-usage')->assertForbidden();
});

test('guests are redirected to the panel login', function () {
    get('/admin/mcp-usage')->assertRedirect();
});
