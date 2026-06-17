<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add venue review support (M053/S03/T01, MEM720/D083).
     *
     * Venue reviews target `locations` (reviewable_type = App\Models\Location)
     * and have NO GM, so reviews.gm_profile_id must become nullable. Aggregate
     * average_rating/review_count columns are added to `locations`, mirroring
     * gm_profiles (migration 2026_04_23_120524) byte-for-byte.
     *
     * The gm_profile_id change follows the doctrine/pgsql-safe sequence from
     * S03-RESEARCH: drop the existing FK, alter the column to nullable, then
     * re-add the FK nullable with cascadeOnDelete. Each step runs in its own
     * Schema::table closure so PostgreSQL sequences the constraint drop before
     * the type change (a single closure can deadlock on the dependent index).
     */
    public function up(): void
    {
        // (a) reviews.gm_profile_id — make nullable while preserving the FK cascade.
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['gm_profile_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->uuid('gm_profile_id')->nullable()->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('gm_profile_id')->references('id')->on('gm_profiles')->cascadeOnDelete();
        });

        // (b) locations — aggregate columns mirroring gm_profiles.
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('review_count')->default(0);
        });
    }

    /**
     * Reverse the safely-reversible parts.
     *
     * The `locations` aggregate columns are dropped. However,
     * reviews.gm_profile_id is INTENTIONALLY LEFT NULLABLE: once venue reviews
     * exist they store NULL in this column, so re-imposing NOT NULL would be
     * destructive (rows would have to be deleted) and re-adding a non-null FK
     * would fail. Per decision D085(4), this change is treated as
     * irreversible. We do not touch the nullable FK created in up(); leaving it
     * keeps cascade-delete intact and keeps up()/down() idempotent.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['average_rating', 'review_count']);
        });

        // reviews.gm_profile_id: deliberately NOT reverted to NOT NULL (D085(4)).
        // Re-imposing NOT NULL is destructive once venue reviews (gm_profile_id=NULL) exist.
    }
};
