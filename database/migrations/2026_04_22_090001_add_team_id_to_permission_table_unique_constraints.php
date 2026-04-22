<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace primary keys on model_has_roles and model_has_permissions with
     * UNIQUE constraints that include team_id.
     *
     * The prior migration (add_team_id_to_permission_tables) dropped team_id
     * from the PK to make it nullable — PostgreSQL PKs cannot contain NULL.
     * However, excluding team_id means a user can only hold a given role once
     * across ALL team scopes, which breaks multi-team admin support.
     *
     * PostgreSQL UNIQUE constraints DO allow NULL columns (NULL != NULL for
     * uniqueness), so we can enforce (team_id, role_id, model_id, model_type)
     * uniqueness while still supporting global roles (team_id IS NULL).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('model_has_roles', function ($t) {
                // MySQL/MariaDB: drop PK, add unique with team_id
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $keys = $sm->listTableDetails('model_has_roles')->getIndexes();
                foreach ($keys as $index) {
                    if ($index->isPrimary()) {
                        $t->dropPrimary();
                        break;
                    }
                }
                $t->unique(['team_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_team_role_model_unique');
            });
            Schema::table('model_has_permissions', function ($t) {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $keys = $sm->listTableDetails('model_has_permissions')->getIndexes();
                foreach ($keys as $index) {
                    if ($index->isPrimary()) {
                        $t->dropPrimary();
                        break;
                    }
                }
                $t->unique(['team_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_team_perm_model_unique');
            });

            return;
        }

        $this->fixTable(
            'model_has_roles',
            'model_has_roles_pkey',
            'model_has_roles_team_role_model_unique',
            'role_id'
        );
        $this->fixTable(
            'model_has_permissions',
            'model_has_permissions_pkey',
            'model_has_permissions_team_perm_model_unique',
            'permission_id'
        );
    }

    private function fixTable(
        string $table,
        string $existingPKeyName,
        string $newUniqueName,
        string $entityFkCol,
    ): void {
        // Idempotent: check if new unique constraint already exists
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = ? AND table_name = ? AND table_schema = 'public'
        ", [$newUniqueName, $table]);

        if ($exists) {
            return;
        }

        // Drop existing PK
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

        // Create UNIQUE constraint with team_id included
        // PostgreSQL UNIQUE allows NULL values (each NULL is considered distinct for uniqueness)
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$newUniqueName} UNIQUE (team_id, {$entityFkCol}, model_id, model_type)"
        );
    }

    public function down(): void
    {
        // Intentionally left empty — reverting to the old PK structure
        // is destructive and could cause data loss for multi-scope assignments.
    }
};
