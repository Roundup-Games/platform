<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->string('invitee_email')->nullable()->after('user_id');
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->string('invitee_email')->nullable()->after('user_id');
        });

        // Partial unique indexes: prevents duplicate email invites per entity,
        // while allowing multiple NULL invitee_email rows (normal user-based participants)
        DB::statement('CREATE UNIQUE INDEX game_participants_invite_email_unique ON game_participants (game_id, invitee_email) WHERE invitee_email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX campaign_participants_invite_email_unique ON campaign_participants (campaign_id, invitee_email) WHERE invitee_email IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS game_participants_invite_email_unique');
        DB::statement('DROP INDEX IF EXISTS campaign_participants_invite_email_unique');

        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('invitee_email');
            $table->uuid('user_id')->nullable(false)->change();
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn('invitee_email');
            $table->uuid('user_id')->nullable(false)->change();
        });
    }
};
