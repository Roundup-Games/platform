<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Query-shape indexes for the badge engine's list/rollup surfaces
 * (M071 review follow-up).
 *
 *  - badge_events.badge_user_id — the audit trail is always read per
 *    hold (BadgeEventsRelationManager, evidence lookups joined on
 *    badge_events.badge_user_id); the FK column had no index, so every
 *    per-hold read was a sequential scan.
 *  - badge_user.badge_definition_id — rarity recomputation counts
 *    holders per definition (BadgesRarity::computeFor runs one
 *    where('badge_definition_id')->count() per definition); the
 *    existing unique(user_id, badge_definition_id) cannot serve
 *    definition-only probes (user_id is the leading column).
 *  - badge_events.actor_id — the audit trail is append-only and
 *    actor_id is nullOnDelete: without an index every user hard-delete
 *    seq-scans the whole (ever-growing) trail.
 *
 * Both land as plain secondary indexes — no constraint semantics
 * change; safe to run online (CREATE INDEX, not CONCURRENTLY — the
 * tables ship empty at this point in the release train).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badge_events', function (Blueprint $table): void {
            $table->index(['badge_user_id']);
            $table->index(['actor_id']);
        });

        Schema::table('badge_user', function (Blueprint $table): void {
            $table->index(['badge_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::table('badge_events', function (Blueprint $table): void {
            $table->dropIndex(['badge_user_id']);
            $table->dropIndex(['actor_id']);
        });

        Schema::table('badge_user', function (Blueprint $table): void {
            $table->dropIndex(['badge_definition_id']);
        });
    }
};
