<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the reviews table for the two-tier review system.
     *
     * Reviews are polymorphic: a review can target a Game (per-session)
     * or a Campaign (per-campaign) via reviewable_type / reviewable_id.
     *
     * reviewable_id is varchar(36) because Game and Campaign models use
     * UUID primary keys. Existing integer-keyed models store fine as strings.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic reviewable (Game, Campaign, etc.)
            $table->string('reviewable_type');
            $table->string('reviewable_id', 36);

            // Reviewer
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();

            // The GM being reviewed
            $table->uuid('gm_profile_id');
            $table->foreign('gm_profile_id')->references('id')->on('gm_profiles')->cascadeOnDelete();

            // Rating 1-5 with database-level CHECK constraint
            $table->unsignedTinyInteger('rating');

            // Free-text feedback
            $table->text('body')->nullable();

            // GM proficiency tags (stored as JSON array of GmProficiency values)
            $table->json('proficiency_tags')->nullable();

            // Moderation: published / hidden / reported
            $table->string('status')->default('published');

            // Report tracking
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            // Future: GM reply
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            // One review per reviewer per reviewable
            $table->unique(['reviewable_type', 'reviewable_id', 'reviewer_id'], 'reviews_reviewable_unique');

            // Lookup indexes
            $table->index('gm_profile_id');
            $table->index('reviewer_id');
        });

        // Add CHECK constraint for rating range (PostgreSQL)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating >= 1 AND rating <= 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
