<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $default = json_encode([
            'new_follower' => ['database' => true, 'mail' => false],
            'game_invitation' => ['database' => true, 'mail' => true],
            'campaign_invitation' => ['database' => true, 'mail' => true],
            'team_invitation' => ['database' => true, 'mail' => true],
            'session_added_to_campaign' => ['database' => true, 'mail' => false],
            'new_application' => ['database' => true, 'mail' => true],
            'application_approved' => ['database' => true, 'mail' => true],
            'application_rejected' => ['database' => true, 'mail' => true],
            'participant_joined' => ['database' => true, 'mail' => false],
            'participant_removed' => ['database' => true, 'mail' => true],
            'team_member_removed' => ['database' => true, 'mail' => true],
            'game_cancelled' => ['database' => true, 'mail' => true],
            'game_completed' => ['database' => true, 'mail' => false],
            'campaign_cancelled' => ['database' => true, 'mail' => true],
            'campaign_completed' => ['database' => true, 'mail' => false],
        ]);

        Schema::table('users', function (Blueprint $table) use ($default) {
            $table->json('notification_settings')->nullable()->default($default);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};
