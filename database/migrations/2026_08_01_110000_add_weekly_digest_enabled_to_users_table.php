<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add weekly_digest_enabled to users.
 *
 * The weekly digest is the cost-conscious alternative to per-category email:
 * users who keep email OFF for noisy/informational categories (the default)
 * still receive ONE weekly summary of their unread in-app notifications,
 * keeping them in contact with the platform at ~1/50th the email volume.
 *
 * Defaults to true: for a user who never touches their settings, the digest
 * replaces potentially dozens of individual emails they would otherwise miss
 * entirely (since those categories default to email-off). Users who prefer no
 * summary email at all can opt out with a single toggle.
 *
 * Named weekly_digest_enabled (not digest_enabled) so future digest cadences
 * (daily, monthly) can coexist without renaming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('weekly_digest_enabled')->default(true)->after('notification_settings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('weekly_digest_enabled');
        });
    }
};
