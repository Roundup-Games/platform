<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log-derived alerting backbone (observability 2026-11-03).
 *
 * One row per distinct alertable log message, identified by the sha256 of
 * the message text. App\Logging\AlertableErrorTap (wired into the default
 * stack channel) upserts every record the app logs at WARNING or above:
 * occurrences increments, last_seen_at moves forward, the first context
 * wins, message_hash never changes.
 *
 * Consumers:
 *  - observability:scan (every 5 min) — pages the operator (email + optional
 *    Discord) about config('observability.critical_patterns') matches that
 *    have never been notified (last_notified_at null) and fired within 24h;
 *    also retires non-critical rows older than 7 days via resolved_at.
 *  - observability:digest (daily 06:30) — consolidated unresolved summary.
 *
 * last_notified_at is the paging watermark (scan AND digest set it);
 * notified_at was superseded during design — only last_notified_at exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertable_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // sha256 of the log message — the row identity. One row per
            // distinct message; the unique index is what the tap's upsert
            // conflicts on.
            $table->string('message_hash')->unique();
            $table->text('message');
            $table->string('level', 16);
            $table->string('channel', 64);
            $table->jsonb('context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(1);
            $table->timestampTz('first_seen_at');
            // Index feeds the scan's 24h freshness window and the digest's
            // ordering (both navigate by recency).
            $table->timestampTz('last_seen_at')->index();
            // Paging watermark — set by observability:scan and
            // observability:digest; null means "never paged".
            $table->timestampTz('last_notified_at')->nullable();
            // Non-critical noise older than 7 days is retired by scan.
            $table->timestampTz('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertable_errors');
    }
};
