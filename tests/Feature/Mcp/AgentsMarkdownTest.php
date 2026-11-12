<?php

use App\Http\Controllers\AgentsMarkdownController;
use App\Livewire\Settings\AgentTokens;
use App\Mcp\Servers\RoundupServer;
use App\Models\User;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Attributes\Description;
use Livewire\Livewire;

use function Pest\Laravel\get;

// ── GET /agents.md — public agent self-onboarding doc ───────────────
//
// Contract: the doc an AI personal assistant fetches to onboard itself
// onto the platform's MCP surface. Route-level: 200 text/markdown, never
// hijacked by the bare-path /{path} locale-redirect fallback (mirrors
// the llms.txt route pin). Body-level: an H1-first markdown body with
// zero HTML chrome and zero entities (the markdown/*.blade.php
// discipline). Content-level: the tool catalog section is GENERATED
// from RoundupServer::TOOL_REGISTRY — names, scopes, and purposes may
// never drift in either direction — the advertised protocol versions
// are derived from the installed laravel/mcp package's
// ProtocolVersion::initializeSupported() constant, and the
// operating-rule numbers (rate limits, idempotency replay window) are
// derived from the config McpAuthentication and HandlesIdempotency
// actually enforce.
//
// K004: assertions are structural (status, headers, registry-derived
// names, vendor-derived versions) — never lang/ prose. The one literal
// marker is the never-confirm safety sentence, which lives in the
// EN-only view itself, not in lang/.

/**
 * The catalog rows parsed out of /agents.md, in document order.
 * Row format is the contract (one line per tool, exactly):
 *   - `name` — scope `mcp:read` — one-line purpose
 *
 * @return list<array{name: string, scope: string, purpose: string}>
 */
function agentsDocCatalogRows(string $body): array
{
    $matched = preg_match_all(
        '/^- `([^`]+)` — scope `(mcp:[a-z]+)` — (.+)$/m',
        $body,
        $matches,
        PREG_SET_ORDER,
    );

    if ($matched === false || $matched === 0) {
        throw new RuntimeException('The /agents.md tool catalog has no parseable rows — the generated section format is broken.');
    }

    $rows = [];

    foreach ($matches as $match) {
        $rows[] = [
            'name' => $match[1],
            'scope' => $match[2],
            'purpose' => $match[3],
        ];
    }

    return $rows;
}

/**
 * The expected rate-limit clause for a limit/decay pair — mirrors the
 * controller's perWindow() humanizer on purpose: if either side changes,
 * this pin forces the two back into agreement.
 */
function agentsDocRateClause(int $limit, int $decaySeconds): string
{
    return $limit.' per '.match ($decaySeconds) {
        1 => 'second',
        60 => 'minute',
        3_600 => 'hour',
        default => $decaySeconds.'-second window',
    };
}

/**
 * The expected idempotency replay window — mirrors the controller's
 * humanizeDuration() on purpose, same as agentsDocRateClause().
 */
function agentsDocIdempotencyWindow(int $seconds): string
{
    if ($seconds >= 3_600 && $seconds % 3_600 === 0) {
        $hours = intdiv($seconds, 3_600);

        return $hours.' '.($hours === 1 ? 'hour' : 'hours');
    }

    if ($seconds >= 60 && $seconds % 60 === 0) {
        $minutes = intdiv($seconds, 60);

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    return $seconds.' '.($seconds === 1 ? 'second' : 'seconds');
}

/**
 * The expected purpose for a registered tool: the first sentence of its
 * Description attribute — the same text tools/list advertises. Mirrors
 * the controller's extraction on purpose: if either side changes, this
 * pin forces the two back into agreement.
 */
function agentsDocExpectedPurpose(string $toolClass): string
{
    $attributes = (new ReflectionClass($toolClass))->getAttributes(Description::class);

    if ($attributes === []) {
        return '';
    }

    $attribute = $attributes[0]->newInstance();
    $description = $attribute instanceof Description ? $attribute->value : '';
    $cut = strpos($description, '. ');

    return $cut === false ? rtrim($description) : substr($description, 0, $cut + 1);
}

describe('GET /agents.md — route + markdown discipline', function () {
    it('serves 200 text/markdown — never the locale fallback redirect', function () {
        // assertOk pins the fallback-hijack risk: the bare-path catch-all
        // would answer 302 -> /en/agents.md instead.
        get('/agents.md')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    });

    it('renders an H1-first body with H2 sections, zero HTML chrome, zero entities', function () {
        $body = get('/agents.md')->assertOk()->content();
        $lines = explode("\n", $body);

        expect($lines[0])->toStartWith('# ')
            ->and($body)->toMatch('/^## /m')
            // Zero HTML chrome (the markdown/*.blade.php discipline): no
            // tag-ish '<x' anywhere — these docs are pure markdown.
            ->and($body)->not->toMatch('/<[a-zA-Z!\/]/')
            // And no HTML entities: Blade's {{ }} would entity-encode
            // apostrophes into the body; the view interpolates raw.
            ->and($body)->not->toMatch('/&(?:amp|quot|lt|gt|#0?39);/');
    });
});

describe('GET /agents.md — generated tool catalog parity', function () {
    it('documents exactly the TOOL_REGISTRY — names, scopes, and registry order, both directions', function () {
        $rows = agentsDocCatalogRows(get('/agents.md')->assertOk()->content());

        expect($rows)->toHaveCount(count(RoundupServer::TOOL_REGISTRY));

        // Documented names in document order === the registry in
        // registry order (the groups render in McpAbility order, which
        // the registry already follows).
        expect(array_column($rows, 'name'))->toBe(array_keys(RoundupServer::TOOL_REGISTRY));

        // Per-tool scope equality: the doc's scope is the ability the
        // middleware actually enforces (requiredAbilityFor), nothing
        // looser, nothing tighter.
        foreach ($rows as $row) {
            $ability = RoundupServer::requiredAbilityFor($row['name']);

            expect($ability)->not->toBeNull()
                ->and($row['scope'])->toBe($ability->value);
        }
    });

    it('derives every purpose from the tool Description attribute — first sentence, never hand-copied', function () {
        $rows = agentsDocCatalogRows(get('/agents.md')->assertOk()->content());

        foreach ($rows as $row) {
            $toolClass = RoundupServer::TOOL_REGISTRY[$row['name']];

            expect($row['purpose'])->toBe(agentsDocExpectedPurpose($toolClass))
                ->and($row['purpose'])->not->toBe('');
        }
    });
});

describe('GET /agents.md — operating-rules parity (config-derived)', function () {
    it('renders the rate-limit clauses from the standard tier the limiter enforces', function () {
        $body = get('/agents.md')->assertOk()->content();

        $tier = config('mcp.rate_limits.tiers.standard');

        // K004-safe: these numbers live in the EN-only view (not lang/),
        // generated from config — the pin is wording-agnostic to the
        // surrounding prose but exact about the enforced values.
        expect($body)->toContain('Read-class calls: '.agentsDocRateClause(
            (int) $tier['read']['limit'],
            (int) $tier['read']['decay_seconds'],
        ).'.')
            ->and($body)->toContain('Write-class calls: '.agentsDocRateClause(
                (int) $tier['write']['limit'],
                (int) $tier['write']['decay_seconds'],
            ).' (standard tier)');
    });

    it('renders the idempotency replay window and its expiry boundary', function () {
        $body = get('/agents.md')->assertOk()->content();

        expect($body)->toContain('persists for '.agentsDocIdempotencyWindow(
            (int) config('mcp.idempotency.ttl_seconds'),
        ))
            // The boundary sentence is the safety copy: an agent that
            // retries across days must know the replay ends. Lives in
            // the EN-only view, so the literal marker is fair to pin.
            ->and($body)->toContain('a retry after that executes anew');
    });
});

describe('GET /agents.md — wire contract pins', function () {
    it('pins the /mcp endpoint, the bearer pattern, and the deployment URL', function () {
        $body = get('/agents.md')->assertOk()->content();

        expect($body)->toContain('POST /mcp')
            ->and($body)->toContain('Authorization: Bearer')
            ->and($body)->toContain(rtrim((string) config('app.url'), '/').'/mcp');
    });

    it('renders an absolute deployment server URL — never a relative or empty-root one', function () {
        get('/agents.md')->assertOk();

        // The single most load-bearing line for a raw-HTTP agent: the
        // rendered URL must parse as scheme://host/mcp whatever APP_URL
        // holds (empty falls back to https://roundup.games — the
        // controller's defensive default).
        $url = rtrim((string) config('app.url'), '/').'/mcp';

        expect(parse_url($url, PHP_URL_SCHEME))->not->toBeEmpty()
            ->and(parse_url($url, PHP_URL_HOST))->not->toBeEmpty()
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/mcp');
    });

    it('advertises exactly the protocol versions the installed laravel/mcp package supports', function () {
        $body = get('/agents.md')->assertOk()->content();

        // The vendor constant is the single source of truth — the exact
        // list the live server answers an initialize handshake with, so
        // the document cannot drift from the deployment (or the package
        // upgrade under it).
        foreach (AgentsMarkdownController::supportedProtocolVersions() as $version) {
            expect($body)->toContain($version);
        }

        expect(AgentsMarkdownController::supportedProtocolVersions())
            ->toBe(ProtocolVersion::initializeSupported());

        // And nothing stale: a version the initialize handshake does not
        // support must never be advertised (e.g. the legacy 2024-11-05
        // or the discovery-handshake 2026-07-28).
        $supported = ProtocolVersion::initializeSupported();

        foreach (ProtocolVersion::cases() as $case) {
            if (! in_array($case->value, $supported, true)) {
                expect($body)->not->toContain($case->value);
            }
        }
    });

    it('carries the never-confirm waitlist safety rule with its wire markers', function () {
        $body = get('/agents.md')->assertOk()->content();

        expect($body)->toContain('confirmation_required')
            ->and($body)->toContain('confirmation_handle')
            // The rule itself, as a marker: the promoted player confirms
            // personally — the assistant never does. Lives in the EN-only
            // view (not lang/), so the literal sentence is fair to pin.
            ->and($body)->toContain('Never attempt to confirm on your owner\'s behalf')
            ->and($body)->toContain('no tool exists for it');
    });
});

describe('Settings → Agents panel — catalog doc link', function () {
    it('links the developer-accordion catalog note to /agents.md', function () {
        $user = User::factory()->create();

        // K004: the copy assertion goes through __(); the href through
        // the route helper — never literal prose.
        Livewire::actingAs($user)
            ->test(AgentTokens::class)
            ->assertSee(route('agents.markdown'))
            ->assertSee(__('settings.content_agent_catalog_note'));
    });
});
