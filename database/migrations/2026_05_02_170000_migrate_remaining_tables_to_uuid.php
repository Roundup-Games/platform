<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate the last two application tables with auto-increment PKs to UUID v7:
 *   - team_members
 *   - user_relationships
 *
 * Both tables already have UUID FK columns (team_id, user_id, related_user_id,
 * invited_by) from prior migrations — only the PK needs conversion.
 *
 * Vendor/package tables left as-is (out of scope):
 *   migrations, jobs, failed_jobs, permissions, roles,
 *   customers, subscriptions, subscription_items, transactions
 */
return new class extends Migration
{
    /**
     * External FK constraints to drop before PK swap and restore after.
     * These FK columns are already uuid type — we just need to drop+recreate
     * the constraints because PostgreSQL validates FK against referenced PK type.
     */
    private array $fkConstraints = [
        'team_members' => [
            'team_members_team_id_foreign',
            'team_members_user_id_foreign',
            'team_members_invited_by_foreign',
        ],
        'user_relationships' => [
            'user_relationships_user_id_foreign',
            'user_relationships_related_user_id_foreign',
        ],
    ];

    /**
     * Unique constraints to drop and restore.
     */
    private array $uniqueConstraints = [
        'user_relationships' => 'user_relationships_user_id_related_user_id_type_unique',
    ];

    public function up(): void
    {
        foreach (['team_members', 'user_relationships'] as $table) {
            $this->migrateTable($table);
        }
    }

    private function migrateTable(string $table): void
    {
        // 1. Drop FK constraints
        if (isset($this->fkConstraints[$table])) {
            foreach ($this->fkConstraints[$table] as $fkName) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fkName}");
            }
        }

        // 2. Drop unique constraints (will be re-created after PK swap)
        if (isset($this->uniqueConstraints[$table])) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$this->uniqueConstraints[$table]}");
        }
        // Also drop any other unique constraints
        $uniques = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'u'");
        foreach ($uniques as $u) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$u->conname}");
        }

        // 3. Add temp uuid column and backfill
        Schema::table($table, function (Blueprint $t) {
            $t->uuid('new_uuid')->nullable()->after('id');
        });

        $rows = DB::table($table)->get(['id']);
        foreach ($rows as $row) {
            DB::table($table)
                ->where('id', $row->id)
                ->update(['new_uuid' => (string) Str::orderedUuid()]);
        }

        // 4. Swap PK: drop old constraint + sequence, drop id, add uuid id
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_pkey");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
        DB::statement("DROP SEQUENCE IF EXISTS {$table}_id_seq CASCADE");

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('id');
        });

        Schema::table($table, function (Blueprint $t) {
            $t->uuid('id')->primary()->first();
        });

        // Copy from temp column
        DB::statement("UPDATE {$table} SET id = new_uuid");

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('new_uuid');
        });

        // 5. Restore FK constraints
        if ($table === 'team_members') {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $t->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if ($table === 'user_relationships') {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $t->foreign('related_user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            // Restore unique constraint
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS user_relationships_user_id_related_user_id_type_unique'
                .' ON user_relationships (user_id, related_user_id, type)'
            );
        }
    }

    public function down(): void
    {
        // Not reversible — UUID migration is one-way
    }
};
