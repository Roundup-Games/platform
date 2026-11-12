<?php

use App\Enums\McpAbility;
use App\Livewire\Settings\AgentTokens;
use App\Mcp\Servers\RoundupServer;
use App\Mcp\Tools\HostRosterTool;
use App\Mcp\Tools\PlatformInfoTool;
use App\Models\Game;
use App\Models\McpCall;
use App\Models\McpClient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\InteractsWithMcp;

/*
|--------------------------------------------------------------------------
| MCP Consumer Auth (M079/S01/T02, D277)
|--------------------------------------------------------------------------
|
| Pins the D277 mechanics end-to-end: Sanctum personal access tokens as
| the substrate (abilities are the mcp:* tool-class scopes, checked via
| tokenCan at dispatch), mcp_clients rows as the consumer identity
| (revoked_at kills the token at the middleware layer), the rate-limited
| settings self-issuance surface, and the mcp_calls audit trail written
| per dispatched call.
|
*/

uses(InteractsWithMcp::class);

// ── Consumer identity gate ───────────────────────────────

test('an agent with a valid client and token receives the tool list', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $response = $this->postMcp($bearer, 'tools/list');

    $response->assertStatus(200);

    $toolNames = collect($response->json('result.tools'))->pluck('name');

    expect($toolNames)->toContain(PlatformInfoTool::NAME)
        ->and($toolNames->all())->toBe(array_keys(RoundupServer::TOOL_REGISTRY))
        ->and($client->isRevoked())->toBeFalse();
});

test('a revoked client is rejected with 401 even while its token row still exists', function () {
    $user = User::factory()->create();

    // revoked_at set WITHOUT deleting the token row: pins that the client
    // revocation kills access at the middleware layer, not merely by
    // removing the credential.
    $newAccessToken = $user->createToken('agent', [McpAbility::Read->value]);
    $client = $user->mcpClients()->create([
        'name' => 'Revoked agent',
        'personal_access_token_id' => $newAccessToken->accessToken->id,
        'revoked_at' => now(),
    ]);
    expect($client->isRevoked())->toBeTrue();

    $this->postMcp($newAccessToken->plainTextToken, 'tools/list')
        ->assertStatus(401)
        ->assertJsonPath('error.reason', 'unauthenticated')
        ->assertJson(['error' => ['message' => 'Unauthenticated.']]);
});

test('a personal access token without a linked client is rejected with 401', function () {
    $user = User::factory()->create();

    // A PAT minted outside the MCP issuance path has no consumer identity.
    $newAccessToken = $user->createToken('stray token', [McpAbility::Read->value]);

    $this->postMcp($newAccessToken->plainTextToken, 'tools/list')->assertStatus(401);
});

test('a session authenticated user without a personal access token is rejected with 401', function () {
    // Sanctum's guard resolves session auth to a TransientToken — the MCP
    // surface deliberately accepts only purpose-issued PATs, so a browser
    // session must never open the agent surface.
    $this->actingAs(User::factory()->create());

    $this->postJson('/mcp', $this->mcpMessage('tools/list'), $this->mcpHeaders('tools/list'))
        ->assertStatus(401)
        ->assertJsonPath('error.reason', 'unauthenticated')
        ->assertJson(['error' => ['message' => 'Unauthenticated.']]);
});

// ── Ability gates ────────────────────────────────────────

test('a token without the tools required ability is rejected with 403 naming the ability', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME);

    $response->assertStatus(403)
        ->assertJsonPath('error.reason', 'ability_denied');

    expect($response->json('error.message'))->toContain(McpAbility::Read->value)
        ->and($response->json('error.message'))->toContain(PlatformInfoTool::NAME);
});

test('the tool list requires the read ability', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);

    $this->postMcp($bearer, 'tools/list')->assertStatus(403);
});

test('a token carrying no mcp abilities at all is rejected with 403', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create(), ['billing:read']);

    $this->postMcp($bearer, 'tools/list')
        ->assertStatus(403)
        ->assertJson(['error' => ['message' => 'This token has no MCP abilities.']]);
});

// ── Audit trail ──────────────────────────────────────────

test('every dispatched tool call writes an mcp_calls audit row', function () {
    $user = User::factory()->create();
    [$client, $bearer] = $this->mcpConsumer($user);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        // platform.info declares no arguments (T03 argument discipline:
        // undeclared arguments are refused before dispatch), so the hashed
        // payload is the empty object.
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME);

    $response->assertStatus(200);

    $call = McpCall::query()->sole();

    expect($call->mcp_client_id)->toBe($client->id)
        ->and($call->user_id)->toBe($user->id)
        ->and($call->tool)->toBe(PlatformInfoTool::NAME)
        // Arguments are hashed, never stored raw: sha256 of the exact JSON
        // payload the agent sent.
        ->and($call->arguments_hash)->toBe(hash('sha256', '{}'))
        ->and($call->latency_ms)->toBeInt();
});

test('a rejected call leaves no audit row because it was never dispatched', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(403);

    expect(McpCall::query()->count())->toBe(0);
});

// ── Settings self-issuance surface (D277) ────────────────

test('a user issues an agent token from settings and it authenticates the mcp surface', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Planner agent')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient')
        ->assertHasNoErrors()
        ->assertSet('newToken', fn (mixed $value): bool => is_string($value) && $value !== '');

    $plaintext = $component->get('newToken');

    $client = $user->mcpClients()->sole();

    expect($client->name)->toBe('Planner agent')
        ->and($client->isRevoked())->toBeFalse()
        ->and($client->personalAccessToken->abilities)->toContain(McpAbility::Read->value);

    // Drop the Livewire session identity so the request below exercises the
    // bearer-PAT path, not Sanctum's session-first branch (a browser session
    // must never open the agent surface — TransientToken is rejected).
    auth()->forgetGuards();

    $this->postMcp($plaintext, 'tools/list')->assertStatus(200);
});

test('revoking a client from settings immediately kills its agent access', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Doomed agent')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient');

    $plaintext = $component->get('newToken');
    $client = $user->mcpClients()->sole();

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->call('revokeClient', $client->id);

    $fresh = $client->fresh();

    expect($fresh->isRevoked())->toBeTrue()
        ->and($fresh->personalAccessToken)->toBeNull();

    // Forget the session identity first, otherwise the 401 below would come
    // from the TransientToken session path instead of the revoked credential.
    auth()->forgetGuards();

    $this->postMcp($plaintext, 'tools/list')->assertStatus(401);
});

test('a user cannot revoke another users agent token', function () {
    $owner = User::factory()->create();
    [$client] = $this->mcpConsumer($owner);

    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)
        ->test(AgentTokens::class)
        ->call('revokeClient', $client->id);

    expect($client->fresh()->isRevoked())->toBeFalse();
});

test('agent token issuance is rate limited to five per hour', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < AgentTokens::ISSUANCE_LIMIT; $i++) {
        Livewire::actingAs($user)
            ->test(AgentTokens::class)
            ->set('name', "Agent {$i}")
            ->set('abilities', [McpAbility::Read->value])
            ->call('createClient')
            ->assertHasNoErrors();
    }

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Agent one too many')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient')
        ->assertHasErrors('name');

    expect($user->mcpClients()->count())->toBe(AgentTokens::ISSUANCE_LIMIT);
});

test('the active-client ceiling refusal does not consume the hourly issuance budget', function () {
    $user = User::factory()->create();

    // Cap the list with direct credential creations (the trait path), so
    // the Livewire action's issuance limiter stays untouched at full.
    for ($i = 0; $i < AgentTokens::MAX_ACTIVE_CLIENTS; $i++) {
        $this->mcpConsumer($user);
    }

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Agent eleven')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient')
        ->assertHasErrors('name');

    // The ceiling refusal answered without spending an issuance attempt:
    // revoking one client and retrying succeeds on a full budget.
    expect(RateLimiter::remaining('mcp-agent-tokens:'.$user->id, AgentTokens::ISSUANCE_LIMIT))
        ->toBe(AgentTokens::ISSUANCE_LIMIT);

    $user->mcpClients()->first()->revoke();

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Agent eleven')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient')
        ->assertHasNoErrors();

    expect($user->mcpClients()->active()->count())->toBe(AgentTokens::MAX_ACTIVE_CLIENTS);
});

test('non admin users cannot grant ops abilities', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'Sneaky ops agent')
        ->set('abilities', [McpAbility::Ops->value])
        ->call('createClient')
        ->assertHasErrors('abilities.0');

    expect($user->mcpClients()->count())->toBe(0);
});

test('a game master can self-issue host consumers from settings', function () {
    $this->ensureGameMasterRoleExists();

    $user = User::factory()->create();
    $user->assignRole('Game Master');

    // The perk's issuance half: the form offers host to active GMs — the
    // GM preset card is unlocked and the Advanced checkbox is offered.
    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->assertSee('value="mcp:host"', false)
        ->assertSee(__('settings.label_agent_preset_gm'))
        ->set('name', 'GM host agent')
        ->set('abilities', [McpAbility::Host->value])
        ->call('createClient')
        ->assertHasNoErrors();

    expect($user->mcpClients()->active()->count())->toBe(1);
});

test('ability presets configure matching ability sets and the gm preset stays locked for non-gms', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(AgentTokens::class);

    // Participant preset: read + write.
    $component->call('selectPreset', AgentTokens::PRESET_PARTICIPANT)
        ->assertSet('abilities', [McpAbility::Read->value, McpAbility::Write->value]);

    // The GM preset is a locked no-op without GM status — abilities are
    // left untouched and the preset marker does not move.
    $component->call('selectPreset', AgentTokens::PRESET_GM)
        ->assertSet('abilities', [McpAbility::Read->value, McpAbility::Write->value])
        ->assertSet('preset', AgentTokens::PRESET_PARTICIPANT);

    // With GM status, the same preset adopts the full host bundle.
    $this->ensureGameMasterRoleExists();
    $user->assignRole('Game Master');

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->call('selectPreset', AgentTokens::PRESET_GM)
        ->assertSet('abilities', [McpAbility::Read->value, McpAbility::Write->value, McpAbility::Host->value]);
});

test('agent cards surface usage stats and recent activity from the audit trail', function () {
    // Frozen on a Wednesday midday in the community zone (R089): the
    // today/this-week windows are community calendar buckets, so the
    // fixtures must pin an unambiguous weekday rather than rely on when
    // the suite happens to run.
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Berlin'));

    $user = User::factory()->create();
    [$client] = $this->mcpConsumer($user);

    $call = fn (string $tool, ?Carbon $at = null) => $client->calls()->create([
        'user_id' => $user->id,
        'tool' => $tool,
        'actor' => 'agent',
        'arguments_hash' => hash('sha256', '{}'),
        'latency_ms' => 12,
        'created_at' => $at ?? now(),
    ]);

    // Two dispatched searches today (Wednesday), one platform check on
    // Monday (this calendar week, but not today), and one call from last
    // week (outside the week window: no stats, but still rides the
    // latest-first activity feed).
    $call('games.search');
    $call('games.search');
    $call('platform.info', now()->subDays(2));
    $call('my.dashboard', now()->subDays(8));

    $cards = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->assertSee(__('settings.label_agent_stat_calls_today'))
        ->assertSee(__('settings.activity_games_search'))
        ->assertSee(__('settings.activity_platform_info'))
        ->assertSee(__('settings.activity_my_dashboard'))
        ->instance()->agentCards;

    $card = $cards[0];

    expect($card['today'])->toBe(2)
        ->and($card['week'])->toBe(3)
        ->and($card['tools'])->toBe(2)
        ->and(count($card['activity']))->toBe(4)
        ->and($card['activity'][0]['label'])->toBe(__('settings.activity_games_search'))
        ->and($card['dormant'])->toBeFalse();
});

test('agents unused for over a month are flagged as dormant', function () {
    $user = User::factory()->create();
    [$client] = $this->mcpConsumer($user);

    $client->personalAccessToken->forceFill(['last_used_at' => now()->subDays(40)])->save();

    $cards = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->assertSee(__('settings.hint_agent_dormant'))
        ->instance()->agentCards;

    expect($cards[0]['dormant'])->toBeTrue();
});

test('a chatty agent does not crowd its siblings out of their activity feeds', function () {
    // M079 review: the feed read was one shared limit(120) across all
    // clients — a single high-volume agent could fill every slot and
    // render its siblings as "no recent activity". The read is bounded
    // per client now; this pins the quiet sibling's feed.
    $user = User::factory()->create();
    [$chatty] = $this->mcpConsumer($user, [McpAbility::Read->value]);
    [$quiet] = $this->mcpConsumer($user, [McpAbility::Read->value]);

    $call = fn (McpClient $consumer, string $tool, int $minutesAgo) => $consumer->calls()->create([
        'user_id' => $user->id,
        'tool' => $tool,
        'actor' => 'agent',
        'arguments_hash' => hash('sha256', '{}'),
        'latency_ms' => 12,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);

    // More recent calls than the old shared 120-row budget could hold.
    for ($i = 0; $i < 130; $i++) {
        $call($chatty, 'games.search', $i);
    }

    // The quiet agent's only call is older than every chatty call — under
    // the shared-limit read it starved; the per-client read must surface it.
    $call($quiet, 'platform.info', 500);

    $cards = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->instance()->agentCards;

    $quietCard = collect($cards)->firstWhere('id', $quiet->id);
    $chattyCard = collect($cards)->firstWhere('id', $chatty->id);

    expect(count($quietCard['activity']))->toBe(1)
        ->and($quietCard['activity'][0]['label'])->toBe(__('settings.activity_platform_info'))
        ->and(count($chattyCard['activity']))->toBe(AgentTokens::ACTIVITY_ROWS);
});

test('interacting with the form drops a displayed plaintext token from component state', function () {
    // Display-once hygiene: after issuance the plaintext rides Livewire
    // state until dismissal. Any other interaction — preset select, revoke,
    // another create attempt — must drop it rather than carry it through
    // the next round-trip payload.
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->set('name', 'My agent')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient');

    expect($component->instance()->newToken)->toBeString();

    $component->call('selectPreset', 'companion');
    expect($component->instance()->newToken)->toBeNull();

    // The dismissal hook stays the explicit clear path.
    $component->set('name', 'Second agent')
        ->set('abilities', [McpAbility::Read->value])
        ->call('createClient')
        ->call('dismissNewToken');

    expect($component->instance()->newToken)->toBeNull();
});

test('users without game master status never see or grant the host ability', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AgentTokens::class)
        ->assertDontSee('value="mcp:host"', false)
        // The locked-state hint points at where the perk lives.
        ->assertSee(__('settings.hint_agent_ability_host_locked'))
        ->set('name', 'Sneaky host agent')
        ->set('abilities', [McpAbility::Host->value])
        ->call('createClient')
        ->assertHasErrors('abilities.0');

    expect($user->mcpClients()->count())->toBe(0);
});

test('host tool dispatch is dual-gated with the game master role across the subscription lifecycle', function () {
    $this->ensureGameMasterRoleExists();

    $user = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $user->id,
        'date_time' => now()->addDays(7),
        'max_players' => 4,
        'name' => ['en' => 'Gate Probe'],
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Host->value]);

    $call = fn () => $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostRosterTool::NAME);

    // Without GM status the host token is inert at the gate — even for
    // the game's own owner (the gate is class access, not per-entity).
    $call()->assertStatus(403)
        ->assertJson(['error' => ['message' => 'The host surface requires an active Game Master membership.']]);

    // GM status (what a GM subscription activates) opens the gate; the
    // tool's per-entity policy still decides inside. (Registrar reset +
    // guard-forgetting are the repo's established post-mutation pattern —
    // the shared-test app otherwise serves the pre-assignment user
    // instance, cached roles relation included, to the next request.)
    $user->assignRole('Game Master');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    auth()->forgetGuards();
    $call()->assertStatus(200);

    // Lapse — the role dies with the subscription — re-closes the gate:
    // no zombie credentials outliving the paid perk.
    $user->removeRole('Game Master');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    auth()->forgetGuards();
    $call()->assertStatus(403)
        ->assertJson(['error' => ['message' => 'The host surface requires an active Game Master membership.']]);

    // Platform Admins pass without GM status (GamePolicy::before parity).
    $user->assignRole('Platform Admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    auth()->forgetGuards();
    $call()->assertStatus(200);
});

test('platform admins can issue host and ops consumers from settings', function () {
    seedRoles();

    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');

    Livewire::actingAs($admin)
        ->test(AgentTokens::class)
        ->set('name', 'Ops consumer')
        ->set('abilities', [McpAbility::Ops->value, McpAbility::Read->value])
        ->call('createClient')
        ->assertHasNoErrors();

    $client = $admin->mcpClients()->sole();

    expect($client->personalAccessToken->abilities)
        ->toContain(McpAbility::Ops->value)
        ->toContain(McpAbility::Read->value);
});
