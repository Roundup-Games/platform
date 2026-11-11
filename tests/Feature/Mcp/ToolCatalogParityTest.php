<?php

use App\Mcp\Servers\RoundupServer;

/*
|--------------------------------------------------------------------------
| Tool Catalog Parity — the drift guard for docs/mcp-tools.md (M079/S05/T01)
|--------------------------------------------------------------------------
|
| The MCP surface is a product for autonomous consumers, so its documented
| catalog must never drift from the registry. This suite parses the
| machine-checkable list in docs/mcp-tools.md and asserts parity with
| RoundupServer::TOOL_REGISTRY in BOTH directions plus scope and argument
| equality:
|
|   - registering a tool without a doc line fails CI,
|   - documenting a tool the registry no longer carries fails CI,
|   - documenting a scope (or argument list) the tool does not declare
|     fails CI.
|
| The catalog line format is the contract (one line per tool, exactly):
|   - tool `name` — scope `ability` — class `read|write|mixed` — args `a, b`
| with `args` being `none` for argument-free tools.
*/

/**
 * The catalog lines parsed out of docs/mcp-tools.md, in document order.
 *
 * @return list<array{name: string, scope: string, class: string, args: string}>
 */
function documentedMcpCatalogLines(): array
{
    $catalog = file_get_contents(base_path('docs/mcp-tools.md'));

    if ($catalog === false) {
        throw new RuntimeException('docs/mcp-tools.md is missing or unreadable — the tool catalog must exist for the agent surface.');
    }

    $matched = preg_match_all(
        '/^- tool `([^`]+)` — scope `([^`]+)` — class `(read|write|mixed)` — args `([^`]*)`$/m',
        $catalog,
        $matches,
        PREG_SET_ORDER,
    );

    if ($matched === false || $matched === 0) {
        throw new RuntimeException('docs/mcp-tools.md contains no parseable catalog lines — the machine-checkable list format is broken.');
    }

    $lines = [];

    foreach ($matches as $match) {
        $lines[] = [
            'name' => $match[1],
            'scope' => $match[2],
            'class' => $match[3],
            'args' => $match[4],
        ];
    }

    return $lines;
}

/**
 * The registry side of the parity comparison: wire name => the scope the
 * middleware enforces (requiredAbilityFor) and the declared argument list
 * (declaredArgumentsFor — the exact schema keys tools/list advertises).
 *
 * @return array<string, array{scope: string, args: string}>
 */
function registeredMcpCatalog(): array
{
    $registered = [];

    foreach (RoundupServer::TOOL_REGISTRY as $name => $toolClass) {
        $ability = RoundupServer::requiredAbilityFor($name);
        $declared = RoundupServer::declaredArgumentsFor($name) ?? [];

        $registered[$name] = [
            'scope' => $ability !== null ? $ability->value : '(declares no ability)',
            'args' => $declared === [] ? 'none' : implode(', ', $declared),
        ];
    }

    return $registered;
}

// ── Set parity, both directions ─────────────────────────

test('the documented tool set equals the registered tool set — no missing, no extra', function () {
    $lines = documentedMcpCatalogLines();
    $documentedNames = array_column($lines, 'name');
    $registeredNames = array_keys(RoundupServer::TOOL_REGISTRY);

    // Registered but undocumented: a tool the catalog lies by omission about.
    $undocumented = array_values(array_diff($registeredNames, $documentedNames));

    // Documented but unregistered: a stale entry promising a removed tool.
    $phantom = array_values(array_diff($documentedNames, $registeredNames));

    expect($undocumented)->toBe([], 'Registered tools missing from docs/mcp-tools.md: '.implode(', ', $undocumented))
        ->and($phantom)->toBe([], 'Documented tools the registry no longer carries: '.implode(', ', $phantom));
});

test('the catalog lists each tool exactly once', function () {
    $names = array_column(documentedMcpCatalogLines(), 'name');

    // A duplicated line collapses silently into a keyed map — count pins it.
    expect(count($names))->toBe(count(array_unique($names)))
        ->and(count($names))->toBe(count(RoundupServer::TOOL_REGISTRY));
});

// ── Field parity: scope and arguments ───────────────────

test('every documented scope matches the scope the middleware enforces', function () {
    $registered = registeredMcpCatalog();

    $mismatches = [];

    foreach (documentedMcpCatalogLines() as $line) {
        $entry = $registered[$line['name']] ?? null;

        if ($entry === null || $entry['scope'] !== $line['scope']) {
            $mismatches[] = sprintf(
                '%s: documented `%s`, registry enforces `%s`',
                $line['name'],
                $line['scope'],
                $entry['scope'] ?? '(unregistered)',
            );
        }
    }

    expect($mismatches)->toBe([], "Scope drift in docs/mcp-tools.md:\n".implode("\n", $mismatches));
});

test('every documented argument list matches the tool schema tools/list advertises', function () {
    $registered = registeredMcpCatalog();

    $mismatches = [];

    foreach (documentedMcpCatalogLines() as $line) {
        $entry = $registered[$line['name']] ?? null;

        if ($entry === null || $entry['args'] !== $line['args']) {
            $mismatches[] = sprintf(
                '%s: documented `%s`, schema declares `%s`',
                $line['name'],
                $line['args'],
                $entry['args'] ?? '(unregistered)',
            );
        }
    }

    expect($mismatches)->toBe([], "Argument drift in docs/mcp-tools.md:\n".implode("\n", $mismatches));
});

// ── Catalog content pins ─────────────────────────────────

test('the catalog documents the boundary map, auth flow, rate limits, and audit shape', function () {
    $catalog = file_get_contents(base_path('docs/mcp-tools.md'));

    expect($catalog)->toBeString();

    $missing = [];

    foreach ([
        'POST /mcp' => 'transport endpoint',
        'No arbitrary SQL' => 'boundary map: no arbitrary SQL',
        'No god-tools' => 'boundary map: no god-tools',
        'Service-layer-only writes' => 'boundary map: service-layer-only writes',
        'INV-15' => 'boundary map: INV-15 participant-write invariant',
        'Fail-closed gate' => 'boundary map: fail-closed dispatch order',
        'idempotency_key' => 'agent-safe write retries',
        'mcp_calls' => 'audit trail',
        'sha256' => 'argument hashing discipline',
    ] as $needle => $why) {
        if (! str_contains($catalog, $needle)) {
            $missing[] = "{$needle} ({$why})";
        }
    }

    expect($missing)->toBe([], 'docs/mcp-tools.md lost sections: '.implode('; ', $missing));
});
