<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change team_id from bigint to varchar(36) on Spatie permission tables.
     *
     * Spatie's base migration creates team_id as unsignedBigInteger. When teams
     * are enabled, setPermissionsTeamId() is used to scope roles/permissions.
     * We need to support both integer Team IDs and UUID Event IDs as team scopes,
     * so team_id must be varchar(36) instead of bigint.
     *
     * For PostgreSQL, we must:
     * 1. Drop indexes on team_id
     * 2. Drop unique constraints involving team_id (roles table)
     * 3. ALTER COLUMN type with USING clause to convert existing ints to strings
     * 4. Recreate indexes and unique constraints
     *
     * Must run AFTER 2026_04_13_000001_add_team_id_to_permission_tables.php
     * (which already made team_id nullable and changed PKs to exclude team_id).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('model_has_permissions', function ($t) {
                $t->string('team_id', 36)->nullable()->change();
            });
            Schema::table('model_has_roles', function ($t) {
                $t->string('team_id', 36)->nullable()->change();
            });
            Schema::table('roles', function ($t) {
                $t->string('team_id', 36)->nullable()->change();
            });

            return;
        }

        // model_has_permissions — no unique constraint on team_id, just index
        $this->changeColumn('model_has_permissions', 'model_has_permissions_team_foreign_key_index', null, null);

        // model_has_roles — no unique constraint on team_id, just index
        $this->changeColumn('model_has_roles', 'model_has_roles_team_foreign_key_index', null, null);

        // roles — has unique constraint (team_id, name, guard_name)
        $this->changeColumn(
            'roles',
            'roles_team_foreign_key_index',
            'roles_team_id_name_guard_name_unique',
            ['team_id', 'name', 'guard_name']
        );
    }

    private function changeColumn(
        string $table,
        string $indexName,
        ?string $uniqueConstraintName,
        ?array $uniqueColumns,
    ): void {
        // Idempotent: skip if already varchar
        $col = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_name = ? AND column_name = 'team_id' AND table_schema = 'public'
        ", [$table]);

        if ($col && $col->data_type === 'character varying') {
            return;
        }

        // Drop index on team_id
        $indexExists = DB::selectOne('
            SELECT 1 FROM pg_indexes
            WHERE indexname = ? AND tablename = ?
        ', [$indexName, $table]);

        if ($indexExists) {
            DB::statement("DROP INDEX {$indexName}");
        }

        // Drop unique constraint (roles table only)
        if ($uniqueConstraintName) {
            $constraintExists = DB::selectOne("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = ? AND table_name = ? AND table_schema = 'public'
            ", [$uniqueConstraintName, $table]);

            if ($constraintExists) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$uniqueConstraintName}");
            }
        }

        // Change column type: bigint → varchar(36), converting existing ints to strings
        DB::statement("ALTER TABLE {$table} ALTER COLUMN team_id TYPE varchar(36) USING team_id::varchar(36)");

        // Recreate index on team_id
        DB::statement("CREATE INDEX {$indexName} ON {$table} (team_id)");

        // Recreate unique constraint (roles table only)
        if ($uniqueConstraintName && $uniqueColumns) {
            $cols = implode(', ', $uniqueColumns);
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$uniqueConstraintName} UNIQUE ({$cols})");
        }
    }

    public function down(): void
    {
        // Intentionally left empty — reverting column types from varchar to bigint
        // is destructive (UUIDs cannot be cast back to int) and unnecessary.
    }
};
