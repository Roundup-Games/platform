<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badge engine runtime tables (D186, docs/spec/badges-achievement-concept.md §2.1–2.2).
 *
 * Three tables:
 *
 *  - badge_definitions — runtime mirror of the code registry, synced
 *    idempotently by `badges:sync` (upsert keyed on `key`). Code is the
 *    source of truth for logic and tiers; this row is what awards join
 *    against and what admins toggle (active) or override display
 *    metadata on. A definition is never hand-authored in the UI.
 *  - badge_user — one row per (user, badge); the composite unique
 *    constraint IS the idempotency backstop: a second award of the same
 *    badge violates the constraint instead of duplicating a shelf entry.
 *    Tier upgrades UPDATE the row; revocation/lapse stamp timestamps
 *    rather than deleting, so the audit trail keeps its referent and
 *    "last held" stays legible (spec principle 4).
 *  - badge_events — append-only audit trail (awarded/upgraded/revoked/
 *    lapsed/requalified) with actor, reason, source and
 *    an evidence morph pointer. created_at only, no updated_at: rows are
 *    immutable facts, cf. ActivityLog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Registry key, e.g. 'debut', 'chartered', 'campaign_compleat'.
            // `badges:sync` upserts on this column.
            $table->string('key')->unique();
            // Stored as plain strings; the model casts to the enums.
            $table->string('category');
            $table->string('mode');
            // Heterogeneous tier thresholds per badge (code is truth),
            // e.g. {"bronze": 5, "silver": 15, "gold": 40}. Null = untiered.
            $table->jsonb('tiers')->nullable();
            // Translation keys; the copy itself lives in lang/{en,de}/badges.php.
            $table->string('name_key');
            $table->string('description_key');
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            // Admin kill-switch for NEW awards (retirement flow, spec §2.3).
            $table->boolean('is_active')->default(true);
            // Definition exists but no evaluator is wired to it yet (S01
            // ships real-but-unwired badges alongside the dormant pair).
            $table->boolean('is_dormant')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('badge_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('badge_definition_id')->constrained('badge_definitions')->cascadeOnDelete();
            $table->string('tier')->nullable();
            $table->string('source');
            $table->timestamp('first_awarded_at');
            // Requalifying-mode badges: the window the qualification covers.
            // Requalification watermark (see BadgeUser docblock): the
            // moment a recurring badge re-qualified — not a window end.
            $table->timestamp('requalified_through')->nullable();
            $table->timestamp('last_awarded_at')->nullable();
            // Failed requalification greys out (spec principle 4) — the row
            // survives for "last held" display instead of being deleted.
            $table->timestamp('lapsed_at')->nullable();
            // Indexed for S02 sweeps over "held but revoked" cohorts.
            $table->timestamp('revoked_at')->nullable()->index();
            $table->text('revoked_reason')->nullable();
            // Polymorphic evidence pointer (subscription, guild link,
            // attendance report, campaign…). Morph id is native uuid (D170).
            $table->string('evidence_type')->nullable();
            $table->uuid('evidence_id')->nullable();
            $table->timestamps();

            // THE idempotency backstop: awarding the same badge to the same
            // user twice must violate this constraint, never insert a row.
            $table->unique(['user_id', 'badge_definition_id']);
        });

        Schema::create('badge_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // FK to badge_user (not the definition): the audit trail records
            // what happened to a held badge row, cascade-deleted with it.
            $table->foreignUuid('badge_user_id')->constrained('badge_user')->cascadeOnDelete();
            $table->string('action');
            // Manual/admin actions record who acted; automatic ones null.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('source');
            $table->string('evidence_type')->nullable();
            $table->uuid('evidence_id')->nullable();
            // Append-only audit trail: no updated_at column. The model runs
            // with $timestamps = false and sets created_at manually
            // (cf. ActivityLog).
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Reverse FK order: badge_events → badge_user → badge_definitions.
        Schema::dropIfExists('badge_events');
        Schema::dropIfExists('badge_user');
        Schema::dropIfExists('badge_definitions');
    }
};
