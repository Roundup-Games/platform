<?php

/**
 * Smoke test: verify the squashed schema dump preserves DB-level constructs
 * that are invisible to Eloquent models but essential for runtime behavior.
 *
 * This test guards against silent omission if the schema dump is regenerated
 * with a different pg_dump version or configuration. The testcontainer runs
 * `php artisan migrate` which loads database/schema/pgsql-schema.sql — if any
 * construct is missing, this test fails before the full suite runs.
 */

use Illuminate\Support\Facades\DB;

it('creates the locations geohash trigger', function () {
    $triggers = DB::select("
        SELECT tgname FROM pg_trigger
        WHERE tgname LIKE '%geohash%'
        AND NOT tgisinternal
    ");

    expect($triggers)->not->toBeEmpty('The geohash auto-trigger must exist on the locations table');
})->group('smoke');

it('creates GIN indexes for full-text and JSONB search', function () {
    $ginIndexes = DB::select("
        SELECT indexname FROM pg_indexes
        WHERE indexdef LIKE '%USING gin%'
    ");

    expect($ginIndexes)->not->toBeEmpty('At least one GIN index must exist for pg_trgm or JSONB search');
})->group('smoke');

it('creates all expected tables from the squashed schema', function () {
    // A representative sample across all domains — not exhaustive, but catches
    // a fundamentally broken dump. If any of these are missing, the schema
    // load failed silently.
    $criticalTables = [
        'users', 'games', 'campaigns', 'events', 'teams',
        'locations', 'game_systems', 'permissions', 'roles',
        'escalated_tickets', 'escalated_settings', 'media',
        'short_links', 'notifications', 'migrations',
    ];

    $schemaBuilder = DB::connection()->getSchemaBuilder();

    foreach ($criticalTables as $table) {
        expect($schemaBuilder->hasTable($table))
            ->toBeTrue("Table '{$table}' must exist after loading the squashed schema");
    }
})->group('smoke');
