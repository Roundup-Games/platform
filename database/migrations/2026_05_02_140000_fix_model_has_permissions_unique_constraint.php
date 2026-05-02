<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix the unique constraint on model_has_permissions to include permission_id.
 *
 * The prior migration (090001_add_team_id_to_permission_table_unique_constraints)
 * was supposed to create a UNIQUE(team_id, permission_id, model_id, model_type)
 * constraint but the old UNIQUE(model_id, model_type, team_id) was never dropped,
 * preventing multiple permissions per user+team.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $old = 'model_has_permissions_model_id_model_type_unique';
        $new = 'model_has_permissions_team_perm_model_unique';

        $oldExists = DB::selectOne("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = ? AND table_name = 'model_has_permissions'
        ", [$old]);

        if ($oldExists) {
            DB::statement("ALTER TABLE model_has_permissions DROP CONSTRAINT {$old}");
        }

        $newExists = DB::selectOne("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = ? AND table_name = 'model_has_permissions'
        ", [$new]);

        if (! $newExists) {
            DB::statement(
                "ALTER TABLE model_has_permissions ADD CONSTRAINT {$new} UNIQUE (team_id, permission_id, model_id, model_type)"
            );
        }
    }

    public function down(): void
    {
        // Intentionally empty — reverting to the broken constraint is not useful.
    }
};
