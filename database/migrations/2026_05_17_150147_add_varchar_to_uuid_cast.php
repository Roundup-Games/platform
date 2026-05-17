<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add an implicit PostgreSQL cast from varchar to uuid.
 *
 * The users.id column is native UUID, while Spatie's polymorphic model_id
 * columns (model_has_roles, model_has_permissions) are varchar(36). PostgreSQL
 * refuses to compare uuid = varchar without an explicit cast, causing errors
 * like "operator does not exist: uuid = character varying" in Spatie queries.
 *
 * This implicit cast lets PostgreSQL transparently coerce varchar UUIDs when
 * comparing against native uuid columns. Safe because all stored values are
 * valid UUID strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP CAST IF EXISTS (varchar AS uuid)');
        DB::statement('CREATE CAST (varchar AS uuid) WITH INOUT AS IMPLICIT');
    }

    public function down(): void
    {
        DB::statement('DROP CAST IF EXISTS (varchar AS uuid)');
    }
};
