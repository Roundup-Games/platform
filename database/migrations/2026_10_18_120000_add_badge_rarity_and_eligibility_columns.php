<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badge rarity columns (D195, M069 S04): the nightly badges:rarity job's
 * private output on badge_definitions.
 *
 * - eligibility — which population the rarity denominator counts
 *   (BadgeEligibility). The ONLY column here owned by badges:sync (it is
 *   registry state mirrored like category/mode).
 * - holders_count / eligible_count / rarity_class / rarity_computed_at —
 *   job-owned derived state: badges:sync NEVER touches them, badges:rarity
 *   recomputes all four nightly at 06:45 (after the 06:30 sweep awards).
 *
 * Kept as denormalized columns (not computed on read) because the gallery
 * and GM-directory surfaces read them for every visitor; the nightly job
 * keeps them fresh and rarity_computed_at makes staleness observable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badge_definitions', function (Blueprint $table): void {
            // Sync-owned (registry state): 'all_users' default keeps every
            // pre-existing row and non-GM-craft badge on the player pool.
            $table->string('eligibility')->default('all_users');
            // Job-owned (badges:rarity): holder/eligible counts feed the
            // rarity tier and the gallery's count display.
            $table->unsignedInteger('holders_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            // Job-owned: rarity tier value (BadgeRarity), null until the
            // first rarity run stamps it.
            $table->string('rarity_class')->nullable();
            $table->timestamp('rarity_computed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('badge_definitions', function (Blueprint $table): void {
            $table->dropColumn(['eligibility', 'holders_count', 'eligible_count', 'rarity_class', 'rarity_computed_at']);
        });
    }
};
