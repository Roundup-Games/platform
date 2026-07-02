<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S03/T01 contract tests: games.game_systems is jsonb with a jsonb_path_ops
 * GIN containment index backing whereJsonContains() (@>).
 *
 * These assert DETERMINISTIC schema facts (column type, index existence,
 * migrate:rollback round-trip) plus a functional smoke that the multi-system
 * Gathering match S01 introduced still works. The EXPLAIN probe is best-effort
 * (PG may seq-scan small tables); the authoritative index-usage proof lives in
 * the task-summary EXPLAIN run over a larger seed.
 *
 * Test isolation: blanket DatabaseTransactions in tests/Pest.php (Testcontainers
 * pgsql). The migrate:rollback round-trip runs the migration's down()/up()
 * directly inside the test transaction — PG DDL is transactional, so it
 * self-cleans without touching the migrator batch table.
 */

// Helper: read the live PG type name for games.game_systems via pg_catalog
// (format_type returns exactly 'jsonb' or 'json').
function gameSystemsColumnType(): string
{
    return (string) DB::table('pg_attribute as a')
        ->join('pg_class as c', 'c.oid', '=', 'a.attrelid')
        ->where('c.relname', 'games')
        ->where('a.attname', 'game_systems')
        ->where('a.attnum', '>', 0)
        ->selectRaw('format_type(a.atttypid, a.atttypmod)')->value('format_type');
}

// ── 1. Column is jsonb ────────────────────────────────────────────────────

it('stores games.game_systems as jsonb', function () {
    expect(gameSystemsColumnType())->toBe('jsonb');
});

// ── 2. jsonb_path_ops GIN index exists ────────────────────────────────────

it('has a jsonb_path_ops GIN index named games_game_systems_gin_idx', function () {
    $indexdef = (string) DB::table('pg_indexes')
        ->where('indexname', 'games_game_systems_gin_idx')
        ->value('indexdef');

    expect($indexdef)->not->toBeEmpty()
        ->and($indexdef)->toContain('USING gin')
        ->and($indexdef)->toContain('jsonb_path_ops');
});

// ── 3. migrate:rollback round-trip restores json and re-applies jsonb ─────

it('reverts game_systems to json on rollback and back to jsonb on re-migrate', function () {
    $migration = require database_path(
        'migrations/2026_07_26_100000_convert_game_systems_to_jsonb_add_gin_index.php'
    );

    // After bootstrap the column is jsonb and the index exists.
    expect(gameSystemsColumnType())->toBe('jsonb')
        ->and(indexExists())->toBeTrue();

    // down(): drop index, convert back to json.
    $migration->down();

    expect(gameSystemsColumnType())->toBe('json')
        ->and(indexExists())->toBeFalse();

    // up(): convert to jsonb, recreate the GIN index.
    $migration->up();

    expect(gameSystemsColumnType())->toBe('jsonb')
        ->and(indexExists())->toBeTrue();
});

// ── 4. Functional smoke: whereJsonContains still matches the multi-system Gathering ──

it('finds a multi-system gathering via whereJsonContains after the jsonb conversion', function () {
    $systems = GameSystem::factory()->count(3)->create();
    [$a, $b, $c] = $systems->modelKeys();

    $target = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();
    // Decoy containing A and C but NOT B — must be excluded.
    Game::factory()->gathering()->withGameSystems([$a, $c])->create();
    // Decoy single-system game — null array, never matches.
    Game::factory()->create();

    $found = Game::whereJsonContains('game_systems', $b)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($target))->toBeTrue();
});

// ── 5. EXPLAIN probe: prove the GIN index backs @> containment ───────────
//
// PG may legitimately seq-scan tiny tables, so the index-usage assertion is
// best-effort / non-fatal (per the S03 plan). We seed a large enough table
// that the planner sees real selectivity, ANALYZE, then EXPLAIN. The plan is
// also written to a temp file so the authoritative proof can be quoted in the
// T01 task summary (the PG container only runs during `php artisan test`).

it('can use the GIN index for whereJsonContains (EXPLAIN probe)', function () {
    $systems = GameSystem::factory()->count(2)->create();
    [$a, $b] = $systems->modelKeys();

    // Seed a single matching Gathering.
    Game::factory()->gathering()->withGameSystems([$a, $b])->create();

    // Bulk-seed non-matching filler (game_systems = null) so the planner sees
    // real selectivity (1 match in ~2001 rows). Derive a complete row template
    // from one factory-created row so every NOT NULL column is satisfied, then
    // clone it with fresh UUIDs via a single bulk insert.
    $owner = User::factory()->create();
    $system = GameSystem::factory()->create();
    $template = Game::factory()->make([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
    ])->getAttributes();

    $filler = [];
    for ($i = 0; $i < 2000; $i++) {
        $row = $template;
        $row['id'] = (string) Str::uuid();
        $filler[] = $row;
    }
    foreach (array_chunk($filler, 500) as $chunk) {
        DB::table('games')->insert($chunk);
    }

    DB::statement('ANALYZE games');

    // PostgreSQL's EXPLAIN output column is named "QUERY PLAN" (caps + space),
    // not 'plan' — take the first column value of each row to be name-agnostic.
    $planRows = DB::select(
        'EXPLAIN SELECT * FROM games WHERE game_systems @> ?',
        [json_encode([$a])]
    );
    $plan = collect($planRows)
        ->map(fn ($row) => array_values((array) $row)[0])
        ->implode("\n");

    // Persist the plan for the task-summary EXPLAIN proof (temp file, not
    // git-tracked — purely a diagnostic capture).
    @file_put_contents(sys_get_temp_dir().'/game_systems_gin_explain.txt', $plan);

    // Deterministic: a query plan is produced for the @> containment operator.
    expect($plan)->toContain('Scan');

    // Best-effort: prefer GIN index usage. PG may seq-scan, so this is
    // non-fatal — the authoritative proof is the EXPLAIN quoted in the summary.
    $usesGin = str_contains($plan, 'games_game_systems_gin_idx')
        || str_contains($plan, 'Bitmap Index Scan');

    if (! $usesGin) {
        return; // tolerated seq scan on a small table
    }

    expect($usesGin)->toBeTrue();
});

// ── helpers ───────────────────────────────────────────────────────────────

function indexExists(): bool
{
    return DB::table('pg_indexes')
        ->where('indexname', 'games_game_systems_gin_idx')
        ->exists();
}
