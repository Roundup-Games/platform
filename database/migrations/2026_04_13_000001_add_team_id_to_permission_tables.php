<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk = $columnNames['team_foreign_key'];

        // Change team_id to nullable on model_has_roles
        Schema::table($tableNames['model_has_roles'], function (Blueprint $t) use ($teamFk) {
            $t->unsignedBigInteger($teamFk)->nullable()->change();
        });

        // Change team_id to nullable on model_has_permissions
        Schema::table($tableNames['model_has_permissions'], function (Blueprint $t) use ($teamFk) {
            $t->unsignedBigInteger($teamFk)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot easily revert nullable change without data loss risk
    }
};
