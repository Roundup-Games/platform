<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change media.model_id from bigint to varchar(36).
     *
     * The media table was created with $table->morphs('model') which makes
     * model_id a bigint. Event models use UUID primary keys, so model_id
     * must be varchar(36) to store them.
     *
     * The compound index media_model_type_model_id_index on (model_type, model_id)
     * must be dropped before the type change and recreated after.
     *
     * Existing integer model_ids (Teams, etc.) are safe in varchar — integers
     * store fine as strings.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('media', function ($table) {
                $table->string('model_id', 36)->change();
            });

            return;
        }

        // Idempotent: skip if already varchar
        $col = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_name = 'media' AND column_name = 'model_id' AND table_schema = 'public'
        ");

        if ($col && $col->data_type === 'character varying') {
            return;
        }

        // Drop the compound index on (model_type, model_id)
        $indexExists = DB::selectOne("
            SELECT 1 FROM pg_indexes
            WHERE indexname = 'media_model_type_model_id_index' AND tablename = 'media'
        ");

        if ($indexExists) {
            DB::statement('DROP INDEX media_model_type_model_id_index');
        }

        // Change column type: bigint → varchar(36), converting existing ints to strings
        DB::statement('ALTER TABLE media ALTER COLUMN model_id TYPE varchar(36) USING model_id::varchar(36)');

        // Recreate the compound index
        DB::statement('CREATE INDEX media_model_type_model_id_index ON media (model_type, model_id)');
    }

    public function down(): void
    {
        // Intentionally left empty — reverting column types from varchar to bigint
        // is destructive (UUIDs cannot be cast back to int) and unnecessary.
    }
};
