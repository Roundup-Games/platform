<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes team_id nullable on model_has_roles and model_has_permissions.
     *
     * Spatie's base migration creates team_id as NOT NULL (part of PK) when
     * teams => true. Global roles (Platform Admin, Games Admin) need team_id = null.
     *
     * PostgreSQL primary keys cannot contain nullable columns, so we must:
     * 1. Drop the existing PK
     * 2. ALTER COLUMN to drop NOT NULL
     * 3. Recreate PK without team_id
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('model_has_roles', function ($t) {
                $t->unsignedBigInteger('team_id')->nullable()->change();
            });
            Schema::table('model_has_permissions', function ($t) {
                $t->unsignedBigInteger('team_id')->nullable()->change();
            });

            return;
        }

        $this->fixTable('model_has_roles', 'role_id');
        $this->fixTable('model_has_permissions', 'permission_id');
    }

    private function fixTable(string $table, string $entityFkCol): void
    {
        // Check current state — idempotent
        $col = DB::selectOne("
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_name = ? AND column_name = 'team_id' AND table_schema = 'public'
        ", [$table]);

        if ($col && $col->is_nullable === 'YES') {
            return;
        }

        // Find and drop existing PK
        $pk = DB::selectOne("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            WHERE tc.table_name = ?
              AND tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema = 'public'
        ", [$table]);

        if ($pk) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$pk->constraint_name}");
        }

        // Make team_id nullable
        DB::statement("ALTER TABLE {$table} ALTER COLUMN team_id DROP NOT NULL");

        // Recreate PK without team_id
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_pkey PRIMARY KEY ({$entityFkCol}, model_id, model_type)"
        );
    }

    public function down(): void
    {
        // Intentionally left empty — reverting nullable on PK columns
        // is destructive and could cause data loss.
    }
};
