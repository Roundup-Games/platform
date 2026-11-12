<?php

use App\Enums\McpAbility;
use App\Http\Middleware\McpAuthentication;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Tools\GamesLeaveTool;
use App\Mcp\Tools\PlatformInfoTool;
use App\Models\McpCall;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tests\Traits\InteractsWithMcp;

/*
|--------------------------------------------------------------------------
| MCP Hardening: Rate Limits, Kill-Switch, Argument Discipline (M079/S01/T03)
|--------------------------------------------------------------------------
|
| Pins the agent-proofing over the S01 surface: per-consumer rate limiting
| (read/write buckets keyed on the mcp_clients uuid, 429 + Retry-After +
| mcp.throttle event), the cache-TTL'd tool kill-switch (no deploy needed),
| the undeclared-argument discipline (agents guess — the refusal names the
| accepted arguments), the fail-closed dispatch (exceptions become 500
| JSON, never stack traces), audit completeness (dispatched calls leave a
| row even for policy-denial outcomes; rejected-before-dispatch calls
| leave an mcp.reject event instead), and the write-tool idempotency
| pattern the first real write tool (S02) adopts via HandlesIdempotency.
|
| Transport hardening pins (CSRF-free agent POST, no-Origin Origin
| validation) live in TransportTest — the agent-POST case there asserts
| no 419/403 for an origin-less, CSRF-less POST.
|
*/

uses(InteractsWithMcp::class);

// ── Per-consumer rate limiting ───────────────────────────

test('a consumer exceeding its read bucket gets 429 with retry-after and an mcp throttle event', function () {
    config(['mcp.rate_limits.tiers.standard.read' => ['limit' => 2, 'decay_seconds' => 600]]);

    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $this->postMcp($bearer, 'tools/list')->assertStatus(200);
    $this->postMcp($bearer, 'tools/list')->assertStatus(200);

    Log::shouldReceive('info')->once()->with('mcp.throttle', Mockery::on(
        fn (array $context): bool => ($context['client_id'] ?? null) === $client->id
            && ($context['bucket'] ?? null) === McpAuthentication::BUCKET_READ
            && ($context['retry_after'] ?? 0) > 0
            && ($context['limit'] ?? null) === 2
    ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $response = $this->postMcp($bearer, 'tools/list');

    $response->assertStatus(429)
        ->assertJsonPath('error.reason', 'rate_limited')
        ->assertJson(['error' => ['message' => 'Too many MCP requests. Retry later.']]);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

test('a throttled tool call is rejected before dispatch and leaves no audit row', function () {
    config(['mcp.rate_limits.tiers.standard.read' => ['limit' => 1, 'decay_seconds' => 600]]);

    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());
    unset($client);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    expect(McpCall::query()->count())->toBe(1);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(429);

    // Rejected before dispatch: structured event only, no second audit row.
    expect(McpCall::query()->count())->toBe(1);
});

test('rate limits are keyed per consumer, not globally', function () {
    config(['mcp.rate_limits.tiers.standard.read' => ['limit' => 1, 'decay_seconds' => 600]]);

    [, $loudBearer] = $this->mcpConsumer(User::factory()->create());
    [, $quietBearer] = $this->mcpConsumer(User::factory()->create());

    $this->postMcp($loudBearer, 'tools/list')->assertStatus(200);
    $this->postMcp($loudBearer, 'tools/list')->assertStatus(429);

    // Reset the harness auth state so the next request re-resolves the
    // bearer credential (the guard instance — and its cached user — lives
    // for the whole test process, not one request).
    auth()->forgetGuards();

    // The second consumer has its own budget on the same surface.
    $this->postMcp($quietBearer, 'tools/list')->assertStatus(200);
});

test('write class tools draw from a tighter bucket than read tools', function () {
    expect(McpAuthentication::bucketFor(McpAbility::Write))->toBe(McpAuthentication::BUCKET_WRITE)
        ->and(McpAuthentication::bucketFor(McpAbility::Host))->toBe(McpAuthentication::BUCKET_WRITE)
        ->and(McpAuthentication::bucketFor(McpAbility::Ops))->toBe(McpAuthentication::BUCKET_WRITE)
        ->and(McpAuthentication::bucketFor(McpAbility::Read))->toBe(McpAuthentication::BUCKET_READ)
        ->and(McpAuthentication::bucketFor(null))->toBe(McpAuthentication::BUCKET_READ)
        // The shipped standard tier keeps the write bucket tighter.
        ->and(config('mcp.rate_limits.tiers.standard.write.limit'))
        ->toBeLessThan(config('mcp.rate_limits.tiers.standard.read.limit'));
});

test('a consumer exhausting its write bucket end to end gets 429 with retry-after and the write bucket name', function () {
    // The bucket routing above is unit-pinned; this exercises the whole
    // gate end to end — a dispatched write tool consumes the WRITE bucket
    // (not the read one), and the 11th call answers 429 + Retry-After
    // with the mcp.throttle event naming the write bucket.
    config(['mcp.rate_limits.tiers.standard.write' => ['limit' => 2, 'decay_seconds' => 600]]);

    [$client, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);

    $leave = fn () => $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => (string) Str::uuid()],
    ], GamesLeaveTool::NAME);

    // Two dispatched calls (each a structured not_found denial — dispatched
    // and audited, consuming the write bucket) before the bucket empties.
    $leave()->assertStatus(200)->assertJsonPath('result.isError', true);
    $leave()->assertStatus(200)->assertJsonPath('result.isError', true);

    Log::shouldReceive('info')->once()->with('mcp.throttle', Mockery::on(
        fn (array $context): bool => ($context['client_id'] ?? null) === $client->id
            && ($context['bucket'] ?? null) === McpAuthentication::BUCKET_WRITE
            && ($context['limit'] ?? null) === 2
    ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $throttled = $leave();

    $throttled->assertStatus(429)
        ->assertJson(['error' => ['message' => 'Too many MCP requests. Retry later.']]);

    expect((int) $throttled->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($throttled->headers->get('X-RateLimit-Limit'))->toBe('2');

    // The two dispatched denials left audit rows; the throttled call none.
    expect(McpCall::query()->count())->toBe(2);
});

test('the mcp limiter serves per-client buckets and fails safe to the standard tier', function () {
    $user = User::factory()->create();
    [$client] = $this->mcpConsumer($user);

    // Unknown tier on the client row: the standard limits must apply.
    $client->forceFill(['rate_limit_tier' => 'nonexistent-tier'])->save();

    $request = HttpRequest::create('/mcp', 'POST');
    $request->attributes->set(McpAuthentication::ATTRIBUTE_CLIENT, $client);
    $request->attributes->set(McpAuthentication::ATTRIBUTE_BUCKET, McpAuthentication::BUCKET_WRITE);

    $limits = McpAuthentication::limitsFor($request);

    expect($limits)->toHaveKeys([McpAuthentication::BUCKET_READ, McpAuthentication::BUCKET_WRITE])
        ->and($limits[McpAuthentication::BUCKET_READ])->toBeInstanceOf(Limit::class)
        ->and($limits[McpAuthentication::BUCKET_READ]->key)->toBe('mcp:'.$client->id.':'.McpAuthentication::BUCKET_READ)
        ->and($limits[McpAuthentication::BUCKET_READ]->maxAttempts)->toBe(config('mcp.rate_limits.tiers.standard.read.limit'))
        ->and($limits[McpAuthentication::BUCKET_WRITE]->key)->toBe('mcp:'.$client->id.':'.McpAuthentication::BUCKET_WRITE)
        ->and($limits[McpAuthentication::BUCKET_WRITE]->maxAttempts)->toBe(config('mcp.rate_limits.tiers.standard.write.limit'));

    // The named limiter the gate applies is the one registered in AppServiceProvider.
    expect(RateLimiter::limiter(McpAuthentication::RATE_LIMITER))->not->toBeNull();
});

// ── Tool kill-switch ─────────────────────────────────────

test('a disabled tool is refused at dispatch with 403 and re-enabling needs no deploy', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);

    // Revoke the tool at runtime — a cache write, not a release.
    McpAuthentication::disableTools([PlatformInfoTool::NAME]);

    expect(McpAuthentication::disabledTools())->toBe([PlatformInfoTool::NAME]);

    Log::shouldReceive('info')->once()->with('mcp.reject', Mockery::on(
        fn (array $context): bool => ($context['reason'] ?? null) === 'tool_disabled'
            && ($context['tool'] ?? null) === PlatformInfoTool::NAME
            && ($context['client_id'] ?? null) === $client->id
    ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)
        ->assertStatus(403)
        ->assertJson(['error' => ['message' => 'The '.PlatformInfoTool::NAME.' tool has been disabled by the platform.']]);

    // Rejected before dispatch: the audit trail still only holds the
    // pre-kill call.
    expect(McpCall::query()->count())->toBe(1);

    // Re-arm: clearing the cache restores the tool immediately.
    McpAuthentication::enableAllTools();

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);
});

test('a forgotten kill-switch self-expires via its cache TTL', function () {
    // The TTL bounds operator error: a switch nobody re-arms stops refusing
    // on its own instead of bricking the tool until someone remembers.
    // One-second TTL, then the cache entry is gone and the tool dispatches.
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());
    unset($client);

    McpAuthentication::disableTools([PlatformInfoTool::NAME], 1);

    expect(McpAuthentication::disabledTools())->toBe([PlatformInfoTool::NAME]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)
        ->assertStatus(403)
        ->assertJson(['error' => ['message' => 'The '.PlatformInfoTool::NAME.' tool has been disabled by the platform.']]);

    // Virtualized TTL expiry: the array cache store's TTL math is
    // Carbon-based (availableAt() on put, now-compare on get), so the
    // wormhole fast-forwards past the one-second TTL — the virtualized-
    // time arch rule forbids real sleep() here.
    $this->travel(3)->seconds();

    expect(McpAuthentication::disabledTools())->toBe([]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)->assertStatus(200);
});

test('the disabled-tool list ignores non-string cache entries', function () {
    Cache::put(
        config('mcp.disabled_tools.cache_key'),
        [PlatformInfoTool::NAME, 42, null, true],
        now()->addHour(),
    );

    expect(McpAuthentication::disabledTools())->toBe([PlatformInfoTool::NAME]);
});

// ── Argument discipline ──────────────────────────────────

test('undeclared arguments are refused before dispatch with the accepted names', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    Log::shouldReceive('info')->once()->with('mcp.reject', Mockery::on(
        fn (array $context): bool => ($context['reason'] ?? null) === 'undeclared_arguments'
            && ($context['tool'] ?? null) === PlatformInfoTool::NAME
            // Argument NAMES ride in the event; values never do.
            && ($context['unexpected'] ?? null) === ['bogus', 'also_bogus']
    ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) ['bogus' => 'a very sensitive value', 'also_bogus' => 1],
    ], PlatformInfoTool::NAME);

    $response->assertStatus(400)
        ->assertJsonPath('error.reason', 'undeclared_arguments')
        ->assertJson([
            'error' => [
                'message' => 'Tool '.PlatformInfoTool::NAME.' does not accept bogus, also_bogus. This tool accepts: no arguments.',
                'accepted_arguments' => [],
                'unexpected_arguments' => ['bogus', 'also_bogus'],
            ],
        ]);

    // The sensitive argument value appears nowhere in the response, and
    // the rejected-before-dispatch call left no audit row.
    expect($response->getContent())->not->toContain('a very sensitive value')
        ->and(McpCall::query()->count())->toBe(0)
        ->and($client->calls()->count())->toBe(0);
});

test('positional arguments are refused the same way', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => ['guessed', 'positionally'],
    ], PlatformInfoTool::NAME)
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool '.PlatformInfoTool::NAME.' does not accept positional arguments. This tool accepts: no arguments.');

    expect(McpCall::query()->count())->toBe(0);
});

// ── Fail-closed dispatch ─────────────────────────────────

test('a dispatch exception becomes 500 json, never a stack trace, and is still audited', function () {
    // app.debug makes the vendor transport rethrow — the mode where a
    // naive pipeline would leak a rendered exception page.
    config(['app.debug' => true]);

    // ServerContext resolves registered tools through the container, so a
    // container swap replaces the real tool for this request.
    $this->app->bind(PlatformInfoTool::class, ExplodingPlatformInfoTool::class);

    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME);

    $response->assertStatus(500)
        ->assertJsonPath('error.reason', 'dispatch_exception')
        ->assertJson(['error' => ['message' => 'The MCP server encountered an error.']]);

    expect($response->getContent())->not->toContain('secret-internal-detail')
        ->not->toContain('Trace');

    // The call DID reach dispatch, so the audit trail holds it.
    $call = McpCall::query()->sole();

    expect($call->mcp_client_id)->toBe($client->id)
        ->and($call->tool)->toBe(PlatformInfoTool::NAME)
        ->and($call->latency_ms)->toBeInt();
});

test('a dispatched call with a policy denial outcome still writes an audit row', function () {
    $this->app->bind(PlatformInfoTool::class, RefusingPlatformInfoTool::class);

    [$client, $bearer] = $this->mcpConsumer(User::factory()->create());

    // The tool refuses at the tool level (isError result), not at the
    // gate: the call was dispatched, so the audit row must exist.
    $this->postMcp($bearer, 'tools/call', [
        'name' => PlatformInfoTool::NAME,
        'arguments' => (object) [],
    ], PlatformInfoTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true);

    $call = McpCall::query()->sole();

    expect($call->mcp_client_id)->toBe($client->id)
        ->and($call->tool)->toBe(PlatformInfoTool::NAME);
});

// ── Rejection observability ──────────────────────────────

test('an unauthenticated request logs an mcp reject event', function () {
    Log::shouldReceive('info')->once()->with('mcp.reject', Mockery::on(
        fn (array $context): bool => ($context['reason'] ?? null) === 'unauthenticated'
            && ($context['client_id'] ?? 'absent') === 'absent'
    ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->postJson('/mcp', $this->mcpMessage('tools/list'), $this->mcpHeaders('tools/list'))
        ->assertStatus(401);
});

// ── Transport hardening pins ─────────────────────────────

test('allowed origins stays deliberately empty so the surface is non browser only', function () {
    // config('mcp.allowed_origins') empty + no Origin header = every
    // non-browser agent passes the v1.2.0 Origin validation (an absent
    // Origin is always allowed). Granting a browser origin is a
    // deliberate act that must show up here, not a wildcard.
    expect(config('mcp.allowed_origins'))->toBe([]);

    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    // The CSRF-free agent POST (no CSRF token, no Origin header) must
    // stay neither 419 (CSRF applied) nor 403 (Origin validation).
    $this->postMcp($bearer, 'server/discover')->assertStatus(200);
});

// ── Write-tool idempotency (HandlesIdempotency pattern) ──

test('a retried write with the same idempotency key replays the first answer without re-executing', function () {
    RecordingWriteTool::$executions = 0;

    $tool = app(RecordingWriteTool::class);

    $first = $tool->handle(new Request([
        RecordingWriteTool::IDEMPOTENCY_KEY_ARGUMENT => 'agent-retry-1',
        'label' => 'first attempt',
    ]));

    // The agent retried with the same key: the stored answer replays and
    // the write executes exactly once.
    $retry = $tool->handle(new Request([
        RecordingWriteTool::IDEMPOTENCY_KEY_ARGUMENT => 'agent-retry-1',
        'label' => 'retry with the same key',
    ]));

    expect((string) $first->content())->toBe((string) $retry->content())
        ->and(json_decode((string) $retry->content(), true)['label'])->toBe('first attempt')
        ->and(RecordingWriteTool::$executions)->toBe(1);
});

test('a different idempotency key or a missing key executes normally', function () {
    RecordingWriteTool::$executions = 0;

    $tool = app(RecordingWriteTool::class);

    $tool->handle(new Request([
        RecordingWriteTool::IDEMPOTENCY_KEY_ARGUMENT => 'key-a',
        'label' => 'a',
    ]));

    $tool->handle(new Request([
        RecordingWriteTool::IDEMPOTENCY_KEY_ARGUMENT => 'key-b',
        'label' => 'b',
    ]));

    // No key at all: idempotency does not engage.
    $tool->handle(new Request(['label' => 'no key']));

    expect(RecordingWriteTool::$executions)->toBe(3);
});

test('failed first attempts are not stored as permanent answers', function () {
    RecordingWriteTool::$executions = 0;
    RecordingWriteTool::$failNext = true;

    $tool = app(RecordingWriteTool::class);

    $request = new Request([
        RecordingWriteTool::IDEMPOTENCY_KEY_ARGUMENT => 'flaky-key',
        'label' => 'flaky',
    ]);

    $failed = $tool->handle($request);
    expect($failed->isError())->toBeTrue();

    RecordingWriteTool::$failNext = false;

    // The retry re-executes instead of replaying the stored failure — the
    // execution counter went 0 → 1 on the recovered attempt alone (the
    // failed attempt never completed its write).
    $recovered = $tool->handle($request);

    expect($recovered->isError())->toBeFalse()
        ->and(RecordingWriteTool::$executions)->toBe(1);
});

// ── Inline fixtures ──────────────────────────────────────

/**
 * Test fixture: the real tool's exploding twin. Tool handle() exceptions
 * are converted to isError results by the vendor's Errable ToolInvoker,
 * so the middleware 500 path is exercised where dispatch actually throws —
 * tool resolution. Inherits the #[Name] attribute (attributes resolve up
 * the class hierarchy), so the wire name stays platform.info.
 */
class ExplodingPlatformInfoTool extends PlatformInfoTool
{
    public function __construct()
    {
        throw new RuntimeException('secret-internal-detail');
    }
}

/**
 * Test fixture: refuses at the tool level (policy-denial outcome) with an
 * isError result — dispatched, so audited.
 */
class RefusingPlatformInfoTool extends PlatformInfoTool
{
    public function handle(Request $request): Response
    {
        return Response::error('This deployment refuses this tool.');
    }
}

/**
 * Test fixture: the write-tool shape S02's first real write tool adopts —
 * a declared idempotency_key argument routed through HandlesIdempotency.
 * Not registered in RoundupServer: the concern's mechanics are exercised
 * directly, exactly as a dispatched write tool would run them.
 */
class RecordingWriteTool extends Tool
{
    use HandlesIdempotency;

    public const NAME = 'test.recording-write';

    public static int $executions = 0;

    public static bool $failNext = false;

    public function schema(JsonSchema $schema): array
    {
        return [
            'label' => $schema->string(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->idempotentResponse($request, function () use ($request): Response {
            if (static::$failNext) {
                return Response::error('transient failure');
            }

            static::$executions++;

            return Response::json([
                'label' => $request->get('label'),
                'executions' => static::$executions,
            ]);
        });
    }
}
