<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Change the notifications.data column from text to jsonb so that
     * PostgreSQL JSON operators (->, ->>, @>, etc.) work correctly.
     *
     * Laravel's default notification table migration creates `data` as text,
     * but PostgreSQL requires native json/jsonb for whereJsonContains() and
     * other JSON query builders.
     */
    public function up(): void
    {
        DB::statement('
            ALTER TABLE notifications
            ALTER COLUMN data TYPE jsonb
            USING data::jsonb
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE notifications
            ALTER COLUMN data TYPE text
            USING data::text
        ');
    }
};
