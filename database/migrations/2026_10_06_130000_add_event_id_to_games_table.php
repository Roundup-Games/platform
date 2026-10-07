<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links games to the event umbrella they are hosted at (M063/S05).
 *
 * A "table" is a regular Game row (a Gathering is the expected shape, but no
 * type restriction is enforced) whose games.event_id points at the hosting
 * Event. The link is a nullable uuid FK with nullOnDelete: deleting an event
 * DETACHES its tables (event_id -> NULL) and never destroys them — tables
 * outlive their umbrella (R059 semantics). Event cancel/complete never
 * touches the link either; only explicit deletion does, and even that only
 * detaches.
 *
 * event_id is indexed explicitly because PostgreSQL does not auto-index FK
 * columns: the ManageEvent Tables tab and event detail pages list tables by
 * event_id on every request, which is a hot lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->uuid('event_id')
                ->nullable()
                ->after('campaign_id');
            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->nullOnDelete();
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
