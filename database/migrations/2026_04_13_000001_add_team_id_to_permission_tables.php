<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Makes team_id nullable on model_has_roles and model_has_permissions
     * to support global role assignments (team_id = null) alongside scoped roles.
     *
     * The base Spatie migration creates these as NOT NULL when teams => true,
     * but we need nullable to store global roles (Platform Admin, Games Admin)
     * that apply across all team contexts.
     *
     * PostgreSQL won't allow ALTER COLUMN on a primary key column, so we must
     * drop the PK first, alter the column, then recreate it.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk = $columnNames['team_foreign_key'];
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->makeNullablePgsql($tableNames['model_has_permissions'], $teamFk);
            $this->makeNullablePgsql($tableNames['model_has_roles'], $teamFk);
        } else {
            // SQLite/MySQL: doctrine/dbal handles this directly
            Schema::table($tableNames['model_has_roles'], function (Blueprint $t) use ($teamFk) {
                $t->unsignedBigInteger($teamFk)->nullable()->change();
            });

            Schema::table($tableNames['model_has_permissions'], function (Blueprint $t) use ($teamFk) {
                $t->unsignedBigInteger($teamFk)->nullable()->change();
            });
        }
    }

    private function makeNullablePgsql(string $table, string $teamFk): void
    {
        // 1. Find the actual PK constraint name (PostgreSQL may auto-generate or use Spatie's custom name)
        $constraint = DB::selectOne("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            WHERE tc.table_name = ?
              AND tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema = 'public'
        ", [$table]);

        if (! $constraint) {
            return; // No PK to worry about
        }

        $pkName = $constraint->constraint_name;

        // 2. Drop the primary key
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$pkName}");

        // 3. Make team_id nullable
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$teamFk} DROP NOT NULL");

        // 4. Recreate primary key (known structure from Spatie migration)
        $otherCols = match ($table) {
            config('permission.table_names.model_has_permissions') => 'permission_id, model_id, model_type',
            config('permission.table_names.model_has_roles') => 'role_id, model_id, model_type',
            default => throw new \RuntimeException("Unknown permission table: {$table}"),
        };

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$pkName} PRIMARY KEY ({$teamFk}, {$otherCols})");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot easily revert nullable change without data loss risk
    }
};
