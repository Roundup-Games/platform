<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PostgreSQL stores JoinSource values as varchar with CHECK constraints,
     * following the same pattern used for ParticipantStatus.
     */
    public function up(): void
    {
        $allowedValues = "ARRAY['friend_invite'::character varying, 'share_link'::character varying, 'application'::character varying]";

        DB::statement("ALTER TABLE campaign_participants ADD COLUMN join_source character varying NULL");
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_join_source_check CHECK ((join_source)::text = ANY ({$allowedValues}::text[]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_join_source_check');
        DB::statement('ALTER TABLE campaign_participants DROP COLUMN join_source');
    }
};
