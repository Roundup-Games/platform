<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate all auxiliary tables from auto-increment integer PKs to UUID v7.
 *
 * Tables migrated (12 total):
 *   - membership_types
 *   - contact_messages
 *   - activity_logs
 *   - bgg_sync_logs
 *   - translations
 *   - linked_accounts
 *   - local_subscriptions
 *   - user_app_visits
 *   - push_subscriptions
 *   - nearby_discovery_views
 *   - game_system_requests
 *   - media (Spatie)
 *
 * Cross-aux FK updated:
 *   - local_subscriptions.membership_type_id → uuid (references membership_types)
 *
 * All other FK columns (user_id, game_system_id, reviewed_by) are already uuid type
 * and reference already-migrated tables — no column type change needed for those.
 *
 * Note: media already has a Spatie `uuid` column, so we use `new_uuid` as the
 * temporary column name to avoid collision.
 */
return new class extends Migration
{
    /**
     * Tables whose PKs are being migrated, in dependency order.
     * membership_types first because local_subscriptions depends on it.
     */
    private array $tables = [
        'membership_types',
        'contact_messages',
        'activity_logs',
        'bgg_sync_logs',
        'translations',
        'linked_accounts',
        'user_app_visits',
        'push_subscriptions',
        'nearby_discovery_views',
        'game_system_requests',
        'media',
        // local_subscriptions last — depends on membership_types
        'local_subscriptions',
    ];

    /**
     * Cross-aux FK mappings: [referenced_table => [[fk_table, fk_column]]].
     */
    private array $fkMap = [
        'membership_types' => [
            ['local_subscriptions', 'membership_type_id'],
        ],
    ];

    /**
     * FK constraints that already point to uuid PKs (users, game_systems).
     * These need to be dropped before PK swap and restored after, because
     * PostgreSQL FK constraints validate against the referenced PK type.
     * Since the referenced PKs are already uuid, and these FK columns are
     * already uuid type, we just need to drop+recreate the constraints.
     */
    private array $existingFkConstraints = [
        'activity_logs' => ['activity_logs_user_id_foreign'],
        'linked_accounts' => ['linked_accounts_user_id_foreign'],
        'local_subscriptions' => [
            'local_subscriptions_user_id_foreign',
            'local_subscriptions_membership_type_id_foreign',
        ],
        'user_app_visits' => ['user_app_visits_user_id_foreign'],
        'push_subscriptions' => ['push_subscriptions_user_id_foreign'],
        'nearby_discovery_views' => ['nearby_discovery_views_user_id_foreign'],
        'game_system_requests' => [
            'game_system_requests_user_id_foreign',
            'game_system_requests_reviewed_by_foreign',
            'game_system_requests_game_system_id_foreign',
        ],
        'bgg_sync_logs' => ['bgg_sync_logs_game_system_id_foreign'],
    ];

    public function up(): void
    {
        $idMaps = [];

        // ── Phase 1: Drop all FK constraints that reference external tables ──
        // This must happen before we drop PKs, because FK constraints pin the
        // referenced column type.
        foreach ($this->existingFkConstraints as $table => $constraints) {
            foreach ($constraints as $fkName) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fkName}");
            }
        }

        // ── Phase 2: Add temp uuid column, backfill, build id maps ──
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('new_uuid')->nullable()->after('id');
            });

            $rows = DB::table($table)->get(['id']);
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['new_uuid' => (string) Str::orderedUuid()]);
            }

            $idMaps[$table] = DB::table($table)->pluck('new_uuid', 'id')->toArray();
        }

        // ── Phase 3: Map old FK values in cross-aux dependent tables ──
        foreach ($this->fkMap as $referencedTable => $dependencies) {
            $map = $idMaps[$referencedTable];
            foreach ($dependencies as [$fkTable, $fkColumn]) {
                foreach ($map as $oldId => $newUuid) {
                    DB::table($fkTable)
                        ->where($fkColumn, $oldId)
                        ->update([$fkColumn => $newUuid]);
                }
            }
        }

        // ── Phase 4: Swap PK for each table ──
        foreach ($this->tables as $table) {
            // Drop the auto-increment sequence and old PK
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_pkey");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
            $seqName = "{$table}_id_seq";
            DB::statement("DROP SEQUENCE IF EXISTS {$seqName} CASCADE");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('id');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->uuid('id')->primary()->first();
            });

            // Copy UUID values from temp column
            DB::statement("UPDATE {$table} SET id = new_uuid");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('new_uuid');
            });
        }

        // ── Phase 5: Convert cross-aux FK columns from bigint to uuid ──
        foreach ($this->fkMap as $referencedTable => $dependencies) {
            foreach ($dependencies as [$fkTable, $fkColumn]) {
                Schema::table($fkTable, function (Blueprint $t) use ($fkColumn) {
                    $t->dropColumn($fkColumn);
                });

                Schema::table($fkTable, function (Blueprint $t) use ($fkColumn, $referencedTable) {
                    $t->uuid($fkColumn)->first();
                    $t->foreign($fkColumn)
                        ->references('id')
                        ->on($referencedTable)
                        ->cascadeOnDelete();
                });
            }
        }

        // ── Phase 6: Re-add FK constraints for external references ──
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE linked_accounts ADD CONSTRAINT linked_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE local_subscriptions ADD CONSTRAINT local_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE user_app_visits ADD CONSTRAINT user_app_visits_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE nearby_discovery_views ADD CONSTRAINT nearby_discovery_views_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES game_systems(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE bgg_sync_logs ADD CONSTRAINT bgg_sync_logs_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES game_systems(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        // Drop external FK constraints
        foreach ($this->existingFkConstraints as $table => $constraints) {
            foreach ($constraints as $fkName) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fkName}");
            }
        }

        // Drop cross-aux FK constraints and revert columns
        foreach ($this->fkMap as $referencedTable => $dependencies) {
            foreach ($dependencies as [$fkTable, $fkColumn]) {
                $fkConstraintName = "{$fkTable}_{$fkColumn}_foreign";
                DB::statement("ALTER TABLE {$fkTable} DROP CONSTRAINT IF EXISTS {$fkConstraintName}");

                Schema::table($fkTable, function (Blueprint $t) use ($fkColumn) {
                    $t->dropColumn($fkColumn);
                });

                Schema::table($fkTable, function (Blueprint $t) use ($fkColumn, $referencedTable) {
                    $t->foreignId($fkColumn)->first()->constrained($referencedTable)->cascadeOnDelete();
                });
            }
        }

        // Revert all PKs to auto-increment (data loss: old int values gone)
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropPrimary('id');
                $t->dropColumn('id');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->id()->first();
            });
        }

        // Re-add external FK constraints
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE linked_accounts ADD CONSTRAINT linked_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE local_subscriptions ADD CONSTRAINT local_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE user_app_visits ADD CONSTRAINT user_app_visits_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE nearby_discovery_views ADD CONSTRAINT nearby_discovery_views_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE game_system_requests ADD CONSTRAINT game_system_requests_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES game_systems(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE bgg_sync_logs ADD CONSTRAINT bgg_sync_logs_game_system_id_foreign FOREIGN KEY (game_system_id) REFERENCES game_systems(id) ON DELETE SET NULL');
    }
};
