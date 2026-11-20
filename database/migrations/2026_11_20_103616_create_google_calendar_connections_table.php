<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable storage for Google Calendar managed-sync connections (M083/S02,
 * D287).
 *
 * ONE ROW PER USER — the unique index on user_id enforces it, and
 * S02-T02's reconnect upsert replaces the row instead of accumulating
 * stale consents, so there is always exactly one live consent per user to
 * reason about.
 *
 * token + refresh_token are encrypted at rest (model casts 'encrypted',
 * mirroring LinkedAccount): the OAuth refresh token is the only long-lived
 * Google credential the platform stores — lang/calendar_sync.php
 * content_scope_storage promises "Roundup stores only the connection
 * itself — an OAuth refresh token", and encrypting that token is part of
 * honoring the promise.
 *
 * source_toggles is jsonb with an all-true default: every source
 * (games, campaign_sessions, events — exactly the enumeration S01's
 * consent copy documents) syncs unless the user turns it off. The model
 * validates the shape on every read AND write so a drifted row fails
 * loudly instead of silently syncing (or skipping) a source.
 *
 * RESTORED POST-SQUASH (2026-11-20): this file was authored for M083/S02
 * and folded into database/schema/pgsql-schema.sql by the 2026-11-20
 * re-squash before any deployed environment could run it — the dump is
 * only ever loaded on a FRESH install (empty migrations table), so every
 * existing environment (production included) would otherwise lack the
 * table. Restored under its ORIGINAL name so the baseline ledger row
 * (id 313) and the migrations directory stay in lockstep: fresh installs
 * skip it (ledger row + table already present via the dump), existing
 * environments run it once. The hasTable guard is belt-and-braces for any
 * database that somehow holds the table without the ledger row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_calendar_connections')) {
            return;
        }

        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_account_email');
            $table->text('token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');
            $table->jsonb('source_toggles')
                ->default('{"games":true,"campaign_sessions":true,"events":true}');
            $table->string('status')->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // One connection per user (reconnect replaces the row). This
            // unique index is also user_id's FK index — Postgres never
            // auto-indexes FK columns (K015), and a second plain btree on
            // the same single column would be pure write overhead, so no
            // separate ->index() is created.
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_connections');
    }
};
