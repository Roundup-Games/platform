<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'email_invite' to the join_source CHECK constraints on both
     * game_participants and campaign_participants tables.
     *
     * The original constraints only allowed friend_invite, share_link, and application.
     * This migration adds the email_invite value introduced by the email invitation feature.
     */
    public function up(): void
    {
        $allowedValues = "ARRAY['friend_invite'::character varying, 'share_link'::character varying, 'application'::character varying, 'email_invite'::character varying]";

        DB::statement('ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_join_source_check');
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_join_source_check CHECK ((join_source)::text = ANY ({$allowedValues}::text[]))");

        DB::statement('ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_join_source_check');
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_join_source_check CHECK ((join_source)::text = ANY ({$allowedValues}::text[]))");
    }

    public function down(): void
    {
        $allowedValues = "ARRAY['friend_invite'::character varying, 'share_link'::character varying, 'application'::character varying]";

        DB::statement('ALTER TABLE game_participants DROP CONSTRAINT IF EXISTS game_participants_join_source_check');
        DB::statement("ALTER TABLE game_participants ADD CONSTRAINT game_participants_join_source_check CHECK ((join_source)::text = ANY ({$allowedValues}::text[]))");

        DB::statement('ALTER TABLE campaign_participants DROP CONSTRAINT IF EXISTS campaign_participants_join_source_check');
        DB::statement("ALTER TABLE campaign_participants ADD CONSTRAINT campaign_participants_join_source_check CHECK ((join_source)::text = ANY ({$allowedValues}::text[]))");
    }
};
