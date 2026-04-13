<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes to frequently queried tables.
     *
     * These indexes target the most common query patterns identified:
     * - Event listing: filtered by is_public + status + ordered by start_date
     * - Event detail: lookup by organizer_id, featured events
     * - Event registrations: lookup by event_id, uniqueness by user+event
     * - Event announcements: lookup by event_id
     * - Team members: lookup by team_id, active membership by user+status
     * - Games: lookup by owner, game_system, visibility
     * - Campaigns: lookup by owner, game_system, visibility
     *
     * Rollback: Each index is named and explicitly dropped in down().
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['is_public', 'status', 'start_date'], 'events_listing_index');
            $table->index('organizer_id', 'events_organizer_index');
            $table->index('is_featured', 'events_featured_index');
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->index('event_id', 'event_registrations_event_index');
            $table->index(['user_id', 'event_id'], 'event_registrations_user_event_index');
        });

        Schema::table('event_announcements', function (Blueprint $table) {
            $table->index('event_id', 'event_announcements_event_index');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->index('team_id', 'team_members_team_index');
            $table->index(['user_id', 'status'], 'team_members_user_status_index');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->index('owner_id', 'games_owner_index');
            $table->index('game_system_id', 'games_system_index');
            $table->index('visibility', 'games_visibility_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->index('owner_id', 'campaigns_owner_index');
            $table->index('game_system_id', 'campaigns_system_index');
            $table->index('visibility', 'campaigns_visibility_index');
        });
    }

    /**
     * Reverse the migrations — drop all performance indexes.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_listing_index');
            $table->dropIndex('events_organizer_index');
            $table->dropIndex('events_featured_index');
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropIndex('event_registrations_event_index');
            $table->dropIndex('event_registrations_user_event_index');
        });

        Schema::table('event_announcements', function (Blueprint $table) {
            $table->dropIndex('event_announcements_event_index');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex('team_members_team_index');
            $table->dropIndex('team_members_user_status_index');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_owner_index');
            $table->dropIndex('games_system_index');
            $table->dropIndex('games_visibility_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_owner_index');
            $table->dropIndex('campaigns_system_index');
            $table->dropIndex('campaigns_visibility_index');
        });
    }
};
