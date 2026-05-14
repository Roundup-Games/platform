<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escalated uses unsignedBigInteger for user references (assigned_to, morphs, etc.)
     * but our User model uses UUID primary keys. This migration converts those columns
     * to PostgreSQL uuid type via drop-and-recreate (tables expected empty at migration time).
     */
    public function up(): void
    {
        $prefix = config('escalated.table_prefix', 'escalated_');

        // Wrap all DDL in a transaction — PostgreSQL supports transactional DDL
        // so a failure halfway through will roll back cleanly.
        DB::transaction(function () use ($prefix) {

        /**
         * Drop all indexes and constraints involving a column on a table.
         */
        $dropColumnDependencies = function (string $table, string $column) {
            // Drop unique/exclusion constraints that involve this column
            $constraints = DB::select("
                SELECT con.conname
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
                JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
                WHERE rel.relname = ?
                  AND att.attname = ?
                  AND con.contype IN ('u', 'x')
            ", [$table, $column]);

            foreach ($constraints as $c) {
                DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$c->conname}\"");
            }

            // Drop indexes (non-constraint) that involve this column
            $indexes = DB::select("
                SELECT indexname FROM pg_indexes
                WHERE tablename = ? AND indexdef LIKE '%\"' || ? || '\"%'
            ", [$table, $column]);

            foreach ($indexes as $idx) {
                // Constraints already dropped above, so this handles remaining plain indexes
                DB::statement("DROP INDEX IF EXISTS \"{$idx->indexname}\"");
            }
        };

        /**
         * Convert a morphs() column pair from bigint to uuid.
         */
        $convertMorph = function (string $table, string $name) use ($dropColumnDependencies) {
            if (! Schema::hasTable($table)) {
                return;
            }

            $idx = $table . '_' . $name . '_type_' . $name . '_id_index';
            $dropColumnDependencies($table, $name . '_id');

            // Drop and re-add as uuid
            DB::statement("ALTER TABLE \"{$table}\" DROP COLUMN IF EXISTS \"{$name}_id\"");
            DB::statement("ALTER TABLE \"{$table}\" ADD COLUMN \"{$name}_id\" UUID NULL");
            DB::statement("CREATE INDEX IF NOT EXISTS \"{$idx}\" ON \"{$table}\" (\"{$name}_type\", \"{$name}_id\")");
        };

        /**
         * Convert a plain unsignedBigInteger column to uuid.
         */
        $convertCol = function (string $table, string $column) use ($dropColumnDependencies) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return;
            }

            $dropColumnDependencies($table, $column);

            DB::statement("ALTER TABLE \"{$table}\" DROP COLUMN IF EXISTS \"{$column}\"");
            DB::statement("ALTER TABLE \"{$table}\" ADD COLUMN \"{$column}\" UUID NULL");
        };

        /**
         * Convert with unique constraint restoration.
         */
        $convertUnique = function (string $table, string $column) use ($convertCol) {
            $constraints = DB::select("
                SELECT con.conname, pg_get_constraintdef(con.oid) AS definition FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
                WHERE rel.relname = ? AND att.attname = ? AND con.contype = 'u'
            ", [$table, $column]);

            foreach ($constraints as $c) {
                DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$c->conname}\"");
            }

            $convertCol($table, $column);

            foreach ($constraints as $c) {
                DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$c->conname}\" {$c->definition}");
            }
        };

        // ── Core ticket tables ───────────────────────────────

        $convertMorph($prefix.'tickets', 'requester');

        $convertCol($prefix.'tickets', 'assigned_to');
        DB::statement("CREATE INDEX IF NOT EXISTS \"{$prefix}tickets_assigned_to_index\" ON \"{$prefix}tickets\" (\"assigned_to\")");

        if (Schema::hasColumn($prefix.'tickets', 'snoozed_by')) {
            $convertCol($prefix.'tickets', 'snoozed_by');
        }

        $convertMorph($prefix.'replies', 'author');
        $convertMorph($prefix.'ticket_activities', 'causer');
        $convertCol($prefix.'ticket_followers', 'user_id');

        // ── Agent/Profile tables ──────────────────────────────

        $convertUnique($prefix.'agent_profiles', 'user_id');
        $convertCol($prefix.'agent_skill', 'user_id');
        $convertCol($prefix.'agent_capacity', 'user_id');

        // ── Content tables ────────────────────────────────────

        $convertCol($prefix.'canned_responses', 'created_by');
        $convertCol($prefix.'macros', 'created_by');
        $convertCol($prefix.'articles', 'author_id');

        // ── Rating tables ─────────────────────────────────────

        $convertMorph($prefix.'satisfaction_ratings', 'rated_by');

        // ── Conversation tables ───────────────────────────────

        $convertCol($prefix.'side_conversations', 'created_by');
        $convertCol($prefix.'side_conversation_replies', 'author_id');
        $convertCol($prefix.'chat_sessions', 'agent_id');

        // ── RBAC/Audit tables ─────────────────────────────────

        $convertCol($prefix.'role_user', 'user_id');
        $convertCol($prefix.'audit_logs', 'user_id');

        // ── Other user-ref tables ─────────────────────────────

        $convertCol($prefix.'mentions', 'user_id');
        $convertCol($prefix.'saved_views', 'user_id');
        $convertCol($prefix.'workflows', 'created_by');
        $convertCol($prefix.'two_factor', 'user_id');
        $convertCol($prefix.'contacts', 'user_id');
        $convertCol($prefix.'import_jobs', 'user_id');
        $convertCol($prefix.'department_agent', 'agent_id');

        // ── Morph tables ──────────────────────────────────────

        $convertMorph($prefix.'api_tokens', 'tokenable');
        $convertMorph($prefix.'custom_field_values', 'entity');
        $convertMorph($prefix.'attachments', 'attachable');

        }); // end DB::transaction
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'This migration is not reversible. bigint→uuid is a one-way conversion. '
            . 'Restore from backup if rollback is required.'
        );
    }
};
