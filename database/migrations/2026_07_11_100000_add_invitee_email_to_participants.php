<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->string('invitee_email')->nullable()->after('user_id');
            $table->index('invitee_email');
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->string('invitee_email')->nullable()->after('user_id');
            $table->index('invitee_email');
        });
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropIndex(['invitee_email']);
            $table->dropColumn('invitee_email');
            $table->uuid('user_id')->nullable(false)->change();
        });

        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropIndex(['invitee_email']);
            $table->dropColumn('invitee_email');
            $table->uuid('user_id')->nullable(false)->change();
        });
    }
};
