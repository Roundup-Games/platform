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
            // PostgreSQL: must drop PK, alter column, recreate PK
            $this->makeNullablePgsql(
                $tableNames['model_has_permissions'],
                $teamFk,
                'model_has_permissions_permission_model_type_primary',
                [$teamFk, 'permission_id', 'model_id', 'model_type']
            );
            $this->makeNullablePgsql(
                $tableNames['model_has_roles'],
                $teamFk,
                'model_has_roles_role_model_type_primary',
                [$teamFk, 'role_id', 'model_id', 'model_type']
            );
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

    private function makeNullablePgsql(string $table, string $teamFk, string $pkName, array $pkColumns): void
    {
        $pkCols = implode(', ', $pkColumns);

        // Drop the primary key constraint
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$pkName}");

        // Alter the column to nullable
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$teamFk} DROP NOT NULL");

        // Recreate the primary key
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$pkName} PRIMARY KEY ({$pkCols})");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot easily revert nullable change without data loss risk
    }
};
