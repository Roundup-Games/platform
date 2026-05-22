<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add trigram GIN indexes for translatable JSONB columns.
 *
 * Queries like `WHERE name->>'en' ILIKE '%term%'` use a leading wildcard,
 * which prevents standard B-tree indexes from helping. pg_trgm GIN indexes
 * on the JSONB extraction expression support these ILIKE '%...%' patterns.
 *
 * Indexed locales: 'en' (primary), 'de' (first secondary).
 * Add additional locale indexes as the platform expands language support.
 *
 * Requires: pg_trgm PostgreSQL extension.
 */
return new class extends Migration
{
    /**
     * Tables, columns, and locales to index.
     * Each entry: [table, column, [locales]]
     *
     * NOTE: $table and $column values are interpolated into DDL statements.
     * This is safe because they are hardcoded static values in this file.
     */
    private array $indexTargets = [
        ['game_systems', 'name', ['en', 'de']],
        ['game_systems', 'description', ['en']],
        ['games', 'name', ['en']],
        ['games', 'description', ['en']],
        ['campaigns', 'name', ['en']],
        ['campaigns', 'description', ['en']],
        ['events', 'name', ['en']],
        ['events', 'description', ['en']],
        ['event_announcements', 'title', ['en']],
        ['event_announcements', 'content', ['en']],
    ];

    public function up(): void
    {
        // Ensure pg_trgm extension is available
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->indexTargets as [$table, $column, $locales]) {
            foreach ($locales as $locale) {
                $indexName = "idx_{$table}_{$column}_{$locale}_trgm";
                $expression = "({$column}->>'{$locale}')";

                // Skip if index already exists (idempotent for re-runs)
                $exists = DB::selectOne("
                    SELECT 1 FROM pg_indexes
                    WHERE indexname = ?
                ", [$indexName]);

                if (! $exists) {
                    DB::statement("CREATE INDEX {$indexName} ON {$table} USING gin ({$expression} gin_trgm_ops)");
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexTargets as [$table, $column, $locales]) {
            foreach ($locales as $locale) {
                $indexName = "idx_{$table}_{$column}_{$locale}_trgm";

                DB::statement("DROP INDEX IF EXISTS {$indexName}");
            }
        }
    }
};
