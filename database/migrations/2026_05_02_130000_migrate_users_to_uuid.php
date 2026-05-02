<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate the users table from auto-increment integer PK to UUID v7.
 *
 * 32 FK references across 31 tables, plus 2 Spatie morph columns and sessions.user_id.
 *
 * Strategy:
 *   1. Add temp `uuid` column to users, backfill with UUID v7.
 *   2. Create temp `_user_uuid_map` table (old_id → new_uuid).
 *   3. Drop all FK constraints pointing at users.id.
 *   4. Drop composite PKs on tables with user_id in PK.
 *   5. For each FK column: add temp `_new_xxx` uuid column, populate via JOIN,
 *      drop old column, rename new column to original name, restore indexes/constraints.
 *   6. Swap users PK: drop old id, rename uuid to id.
 *   7. Restore all FK constraints and composite PKs.
 *   8. Migrate Spatie morph columns (model_id bigint → varchar(36)) using same mapping.
 *   9. Clean up temp mapping table.
 */
return new class extends Migration
{
    /**
     * All FK columns referencing users.id, with their properties.
     * [table, column, nullable, in_composite_pk]
     */
    private array $fkColumns = [
        // table, column, nullable, composite_pk
        ['activity_logs', 'user_id', false, false],
        ['attendance_reports', 'reported_id', false, false],
        ['attendance_reports', 'reporter_id', false, false],
        ['campaign_applications', 'user_id', false, false],
        ['campaign_participants', 'user_id', false, false],
        ['campaigns', 'owner_id', false, false],
        ['event_announcements', 'author_id', false, false],
        ['event_registrations', 'user_id', false, false],
        ['events', 'organizer_id', false, false],
        ['game_applications', 'user_id', false, false],
        ['game_participants', 'user_id', false, false],
        ['game_participants', 'attendance_reported_by', true, false],
        ['game_system_requests', 'user_id', false, false],
        ['game_system_requests', 'reviewed_by', true, false],
        ['games', 'owner_id', false, false],
        ['gm_profiles', 'user_id', false, false],
        ['linked_accounts', 'user_id', false, false],
        ['local_subscriptions', 'user_id', false, false],
        ['nearby_discovery_views', 'user_id', false, false],
        ['push_subscriptions', 'user_id', false, false],
        ['reviews', 'reviewer_id', false, false],
        ['reviews', 'reported_by', true, false],
        ['session_debriefings', 'user_id', false, false],
        ['session_zero_confirmations', 'user_id', true, false],
        ['team_members', 'user_id', false, false],
        ['team_members', 'invited_by', true, false],
        ['teams', 'created_by', false, false],
        ['user_app_visits', 'user_id', false, false],
        ['user_game_system_preferences', 'user_id', false, true],
        ['user_relationships', 'user_id', false, false],
        ['user_relationships', 'related_user_id', false, false],
        ['user_vibe_preferences', 'user_id', false, true],
    ];

    /**
     * Non-FK columns referencing users (no FK constraint, but stores user IDs).
     */
    private array $nonFkColumns = [
        ['sessions', 'user_id', true],
    ];

    public function up(): void
    {
        $mapTable = '_user_uuid_map';
        $tempCol = '_new_uuid';

        // ── Step 1: Add uuid column to users and backfill ─────────
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        $users = DB::table('users')->get();
        foreach ($users as $user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['uuid' => (string) Str::orderedUuid()]);
        }

        // ── Step 2: Create temp mapping table ─────────────────────
        DB::statement("CREATE TABLE {$mapTable} (old_id bigint PRIMARY KEY, new_uuid uuid NOT NULL)");
        DB::statement("INSERT INTO {$mapTable} (old_id, new_uuid) SELECT id, uuid::uuid FROM users");

        // ── Step 3: Drop all FK constraints ───────────────────────
        foreach ($this->fkColumns as [$table, $column]) {
            try {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropForeign([$column]);
                });
            } catch (\Throwable $e) {
                // Constraint may not exist — continue
            }
        }

        // ── Step 4: Drop composite PKs that include user FK columns ──
        // These must be dropped before we can drop the column
        $compositePkTables = array_filter($this->fkColumns, fn($f) => $f[3]);
        foreach ($compositePkTables as [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_pkey");
        }

        // ── Step 5: Migrate each FK column via temp column + JOIN ──
        foreach ($this->fkColumns as [$table, $column, $nullable]) {
            $newCol = $column . $tempCol;

            // Add temp uuid column
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$newCol} uuid" . ($nullable ? '' : ' NOT NULL DEFAULT \'00000000-0000-0000-0000-000000000000\''));

            // Populate via JOIN against mapping table
            DB::statement("UPDATE {$table} SET {$newCol} = m.new_uuid FROM {$mapTable} m WHERE {$table}.{$column} = m.old_id");

            // Drop old column and rename
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
            DB::statement("ALTER TABLE {$table} RENAME COLUMN {$newCol} TO {$column}");

            // Remove default if we added one
            if (!$nullable) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
            }
        }

        // Same for non-FK columns (sessions.user_id)
        foreach ($this->nonFkColumns as [$table, $column, $nullable]) {
            $newCol = $column . $tempCol;

            DB::statement("ALTER TABLE {$table} ADD COLUMN {$newCol} uuid" . ($nullable ? '' : ' NOT NULL DEFAULT \'00000000-0000-0000-0000-000000000000\''));

            DB::statement("UPDATE {$table} SET {$newCol} = m.new_uuid FROM {$mapTable} m WHERE {$table}.{$column} = m.old_id");

            DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
            DB::statement("ALTER TABLE {$table} RENAME COLUMN {$newCol} TO {$column}");

            if (!$nullable) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
            }
        }

        // ── Step 5b: Migrate polymorphic columns (Cashier, Notifications) ──
        // These use morph pattern (xxx_type + xxx_id) and store User IDs.
        // Need to change from bigint to varchar(36) for UUID compatibility.
        $morphColumns = [
            ['table' => 'subscriptions', 'type_col' => 'billable_type', 'id_col' => 'billable_id'],
            ['table' => 'transactions', 'type_col' => 'billable_type', 'id_col' => 'billable_id'],
            ['table' => 'notifications', 'type_col' => 'notifiable_type', 'id_col' => 'notifiable_id'],
        ];

        foreach ($morphColumns as $morph) {
            if (!Schema::hasColumn($morph['table'], $morph['id_col'])) {
                continue;
            }

            $newCol = $morph['id_col'] . '_new';

            // Add temp varchar column
            DB::statement("ALTER TABLE {$morph['table']} ADD COLUMN {$newCol} varchar(36)");

            // Map User model entries using the mapping table
            DB::statement("UPDATE {$morph['table']} SET {$newCol} = m.new_uuid::text FROM {$mapTable} m WHERE {$morph['table']}.{$morph['type_col']} = 'App\\\\Models\\\\User' AND {$morph['table']}.{$morph['id_col']} = m.old_id");

            // For non-User models, keep the existing id as string
            DB::statement("UPDATE {$morph['table']} SET {$newCol} = {$morph['id_col']}::text WHERE {$newCol} IS NULL");

            // Swap columns
            DB::statement("ALTER TABLE {$morph['table']} DROP COLUMN {$morph['id_col']}");
            DB::statement("ALTER TABLE {$morph['table']} RENAME COLUMN {$newCol} TO {$morph['id_col']}");
        }

        // ── Step 6: Swap users PK ─────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->dropPrimary('id');
            $table->dropColumn('id');
        });
        // Add as nullable first, populate, then set NOT NULL + primary
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('id')->nullable()->first();
        });
        DB::statement('UPDATE users SET id = uuid');
        DB::statement('ALTER TABLE users ALTER COLUMN id SET NOT NULL');
        Schema::table('users', function (Blueprint $table) {
            $table->primary('id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        // ── Step 7: Restore composite PKs ─────────────────────────
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->primary(['user_id', 'game_system_id']);
        });
        Schema::table('user_vibe_preferences', function (Blueprint $table) {
            $table->primary(['user_id', 'vibe_preference_value']);
        });

        // ── Step 8: Restore FK constraints ────────────────────────
        // activity_logs
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // attendance_reports
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->foreign('reported_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // campaign_applications
        Schema::table('campaign_applications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // campaign_participants
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // campaigns
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // event_announcements
        Schema::table('event_announcements', function (Blueprint $table) {
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // event_registrations
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // events
        Schema::table('events', function (Blueprint $table) {
            $table->foreign('organizer_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // game_applications
        Schema::table('game_applications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // game_participants
        Schema::table('game_participants', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('attendance_reported_by')->references('id')->on('users')->nullOnDelete();
        });
        // game_system_requests
        Schema::table('game_system_requests', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
        // games
        Schema::table('games', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // gm_profiles
        Schema::table('gm_profiles', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // linked_accounts
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // local_subscriptions
        Schema::table('local_subscriptions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // nearby_discovery_views
        Schema::table('nearby_discovery_views', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // push_subscriptions
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // reviews
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('reviewer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
        });
        // session_debriefings
        Schema::table('session_debriefings', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // session_zero_confirmations
        Schema::table('session_zero_confirmations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // team_members
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });
        // teams
        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });
        // user_app_visits
        Schema::table('user_app_visits', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // user_game_system_preferences
        Schema::table('user_game_system_preferences', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // user_relationships
        Schema::table('user_relationships', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('related_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // user_vibe_preferences
        Schema::table('user_vibe_preferences', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── Step 9: Restore indexes that were lost during column swap ──
        // The column rename preserves most indexes, but we need to ensure indexes exist
        // for frequently queried columns
        DB::statement('CREATE INDEX IF NOT EXISTS activity_logs_user_id_created_at_index ON activity_logs (user_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS event_registrations_user_event_index ON event_registrations (user_id, event_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS game_system_requests_user_id_index ON game_system_requests (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS game_system_requests_user_id_name_index ON game_system_requests (user_id, name)');
        DB::statement('CREATE INDEX IF NOT EXISTS push_subscriptions_user_id_index ON push_subscriptions (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS session_zero_confirmations_user_id_index ON session_zero_confirmations (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS team_members_user_id_index ON team_members (user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS team_members_user_status_index ON team_members (user_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_relationships_user_id_type_index ON user_relationships (user_id, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_relationships_related_user_id_type_index ON user_relationships (related_user_id, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_vibe_preferences_user_id_preference_type_index ON user_vibe_preferences (user_id, preference_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS sessions_user_id_index ON sessions (user_id)');

        // Unique constraints that need restoring (some were auto-restored by rename, ensure they exist)
        // These use the standard naming convention: table_column1_column2_unique
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS campaign_applications_campaign_id_user_id_unique ON campaign_applications (campaign_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS campaign_participants_campaign_id_user_id_unique ON campaign_participants (campaign_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS game_applications_game_id_user_id_unique ON game_applications (game_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS game_participants_game_id_user_id_unique ON game_participants (game_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS gm_profiles_user_id_unique ON gm_profiles (user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS nearby_discovery_views_user_id_unique ON nearby_discovery_views (user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS local_subscriptions_user_id_membership_type_id_unique ON local_subscriptions (user_id, membership_type_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS session_debriefings_game_id_user_id_unique ON session_debriefings (game_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS session_zero_confirmations_session_zero_survey_id_user_id_uniqu ON session_zero_confirmations (session_zero_survey_id, user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_app_visits_user_id_visit_date_unique ON user_app_visits (user_id, visit_date)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_relationships_user_id_related_user_id_type_unique ON user_relationships (user_id, related_user_id, type)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS push_subscriptions_endpoint_user_unique ON push_subscriptions (endpoint, user_id)');
        // reviews unique on reviewable_type, reviewable_id, reviewer_id
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS reviews_reviewable_unique ON reviews (reviewable_type, reviewable_id, reviewer_id)');
        // linked_accounts unique on provider, provider_user_id
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS linked_accounts_provider_provider_user_id_unique ON linked_accounts (provider, provider_user_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS linked_accounts_user_id_provider_index ON linked_accounts (user_id, provider)');

        // ── Step 10: Migrate Spatie morph columns ─────────────────
        // Drop the unique constraint that includes model_id
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_model_id_model_type_unique');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_model_id_model_type_unique');

        // Convert model_id from bigint to varchar(36) and map User model IDs
        // First, convert to text temporarily to hold UUID strings
        DB::statement("ALTER TABLE model_has_roles ADD COLUMN _new_model_id varchar(36)");
        DB::statement("ALTER TABLE model_has_permissions ADD COLUMN _new_model_id varchar(36)");

        // Map User model entries using the mapping table
        DB::statement("UPDATE model_has_roles SET _new_model_id = m.new_uuid::text FROM {$mapTable} m WHERE model_has_roles.model_type = 'App\\\\Models\\\\User' AND model_has_roles.model_id = m.old_id");
        DB::statement("UPDATE model_has_permissions SET _new_model_id = m.new_uuid::text FROM {$mapTable} m WHERE model_has_permissions.model_type = 'App\\\\Models\\\\User' AND model_has_permissions.model_id = m.old_id");

        // For non-User models, keep the existing model_id value as string
        DB::statement("UPDATE model_has_roles SET _new_model_id = model_id::text WHERE model_type != 'App\\\\Models\\\\User' AND _new_model_id IS NULL");
        DB::statement("UPDATE model_has_permissions SET _new_model_id = model_id::text WHERE model_type != 'App\\\\Models\\\\User' AND _new_model_id IS NULL");

        // Swap columns
        DB::statement("ALTER TABLE model_has_roles DROP COLUMN model_id");
        DB::statement("ALTER TABLE model_has_roles RENAME COLUMN _new_model_id TO model_id");
        DB::statement("ALTER TABLE model_has_permissions DROP COLUMN model_id");
        DB::statement("ALTER TABLE model_has_permissions RENAME COLUMN _new_model_id TO model_id");

        // Restore Spatie unique constraints
        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_model_id_model_type_unique UNIQUE (model_id, model_type, team_id)');
        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_model_id_model_type_unique UNIQUE (model_id, model_type, team_id)');

        // ── Step 11: Clean up temp mapping table ──────────────────
        DB::statement("DROP TABLE {$mapTable}");
    }

    public function down(): void
    {
        // Not reversible due to UUID→int data loss. Restore from backup.
        throw new \RuntimeException('This migration cannot be rolled back. Restore from database backup.');
    }
};
