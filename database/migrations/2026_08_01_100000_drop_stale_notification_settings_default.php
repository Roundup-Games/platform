<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the stale, hand-maintained default on users.notification_settings.
 *
 * The original default (2026_04_21_180533) was a hard-coded JSON blob of 15
 * categories with only database/mail keys — no push, and 12 of the now-28
 * categories missing entirely. It drifted from the source of truth
 * (NotificationCategory::defaultSettings()) as new categories and the push
 * channel were added.
 *
 * Reads are already correct regardless: NotificationService::resolveChannels()
 * now falls back per-channel to the enum defaults when a key is absent, so a
 * partial legacy row behaves identically to a null one. Dropping the column
 * default to NULL simply guarantees new rows (and factory-created users in
 * tests) start from a consistent null state that resolves to the full, current
 * default matrix instead of a stale snapshot frozen at migration time.
 *
 * Existing rows are intentionally left untouched: their stored preferences
 * (including explicit user choices) are preserved and merged with defaults at
 * read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_settings')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Restore the original (stale) default so the rollback is honest about
        // what existed before. The value is not re-derived because it was a
        // fixed historical snapshot, not a computed one.
        $legacyDefault = json_encode([
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

        Schema::table('users', function (Blueprint $table) use ($legacyDefault) {
            $table->json('notification_settings')->nullable()->default($legacyDefault)->change();
        });
    }
};
