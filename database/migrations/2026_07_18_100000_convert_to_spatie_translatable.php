<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert to spatie/laravel-translatable JSON columns.
 *
 * Drops the old translations table (empty — zero data loss), wraps existing text
 * values in JSON locale objects, converts 12 columns to JSON type, renames
 * events.content_language → language, and adds teams.language.
 *
 * Columns converted (text → JSON with {locale: value} wrapping):
 *   events:            name, description, short_description
 *   event_announcements: title, content
 *   teams:             description
 *   games:             name, description
 *   campaigns:         name, description
 *   game_systems:      name, description
 *
 * Columns NOT converted (stay as-is):
 *   events.rules, events.schedule, events.divisions, events.amenities,
 *   events.requirements, events.metadata — regular array-cast JSON
 *   games.safety_rules, games.recap, games.location, games.vibe_flags,
 *   games.minimum_requirements — regular array/object JSON
 *   campaigns.safety_rules, campaigns.images, campaigns.location,
 *   campaigns.vibe_flags, campaigns.minimum_requirements — regular JSON
 *   game_system_categories.name — plain text, not translatable
 *   game_system_mechanics.name — plain text, not translatable
 */
return new class extends Migration
{
    /**
     * Tables and their translatable columns that need text → JSON conversion.
     * Each entry: [table => [[column, nullable], ...]]
     */
    private array $translatableColumns = [
        'events' => [
            ['name', false],
            ['description', true],
            ['short_description', true],
        ],
        'event_announcements' => [
            ['title', false],
            ['content', false],
        ],
        'teams' => [
            ['description', true],
        ],
        'games' => [
            ['name', false],
            ['description', false],
        ],
        'campaigns' => [
            ['name', false],
            ['description', false],
        ],
        'game_systems' => [
            ['name', false],
            ['description', true],
        ],
    ];

    /**
     * CAUTION: The down() method extracts only the 'en' key from JSON objects.
     * If users have added translations in other locales (de, etc.), those values
     * will be SILENTLY DROPPED on rollback. Only run down() on a fresh database
     * or after manually backing up non-English translations.
     *
     * NOTE: $table and $column values are interpolated into DDL statements.
     * This is safe because they are hardcoded static values in this file,
     * never derived from user input or dynamic data.
     */

    public function up(): void
    {
        // 1. Drop the old translations table (empty — no data loss)
        Schema::dropIfExists('translations');

        // 2. Wrap existing text values in JSON locale objects AND convert column types.
        //    Combined into a single ALTER TABLE per column to halve lock duration:
        //    each column's UPDATE + type change happens atomically.
        //    Regex check prevents double-wrapping on re-run.
        //
        //    PERF NOTE: ALTER COLUMN TYPE JSONB triggers a full table rewrite on
        //    PostgreSQL. At current scale (< 10K rows per table) this completes in
        //    seconds. For significantly larger tables, consider using pg_repack or
        //    a batched approach to reduce lock duration.
        foreach ($this->translatableColumns as $table => $columns) {
            DB::transaction(function () use ($table, $columns) {
                foreach ($columns as [$column, $nullable]) {
                    DB::statement("
                        ALTER TABLE {$table}
                        ALTER COLUMN {$column} TYPE JSONB
                        USING CASE
                            WHEN {$column} IS NULL THEN NULL
                            WHEN {$column}::text ~ '^\s*[\{\[]' THEN {$column}::jsonb
                            ELSE jsonb_build_object('en', {$column}::text)
                        END
                    ");
                }
            });
        }

        // 3. Rename events.content_language → language
        if (Schema::hasColumn('events', 'content_language')) {
            Schema::table('events', function ($table) {
                $table->renameColumn('content_language', 'language');
            });
        }

        // 4. Add language column to teams
        if (! Schema::hasColumn('teams', 'language')) {
            Schema::table('teams', function ($table) {
                $table->string('language', 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Re-create the translations table
        Schema::create('translations', function ($table) {
            $table->id();
            $table->string('translatable_type');
            $table->string('translatable_id');
            $table->string('locale', 5);
            $table->string('field');
            $table->text('value')->nullable();

            $table->unique(['translatable_type', 'translatable_id', 'locale', 'field'], 'translations_unique');
            $table->index(['translatable_type', 'translatable_id'], 'translations_entity_index');
            $table->index(['locale', 'field'], 'translations_locale_field_index');
        });

        // Unwrap JSON values back to plain text, then convert column types back
        foreach ($this->translatableColumns as $table => $columns) {
            foreach ($columns as [$column, $nullable]) {
                // Extract the 'en' value from JSON objects
                // PostgreSQL: use ->> operator for jsonb text extraction
                DB::statement("
                    UPDATE {$table}
                    SET {$column} = ({$column}::jsonb ->> 'en')::text
                    WHERE {$column} IS NOT NULL
                      AND {$column}::jsonb ? 'en'
                ");

                // Convert back to appropriate text type
                if (in_array($column, ['name', 'title', 'short_description'])) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE VARCHAR(255) USING {$column}::varchar(255)");
                } else {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TEXT USING {$column}::text");
                }
            }
        }

        // Rename events.language back to content_language
        if (Schema::hasColumn('events', 'language') && ! Schema::hasColumn('events', 'content_language')) {
            Schema::table('events', function ($table) {
                $table->renameColumn('language', 'content_language');
            });
        }

        // Drop language column from teams
        if (Schema::hasColumn('teams', 'language')) {
            Schema::table('teams', function ($table) {
                $table->dropColumn('language');
            });
        }
    }
};
