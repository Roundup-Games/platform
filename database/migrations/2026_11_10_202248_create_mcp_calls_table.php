<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP call audit trail (M079/S01/T02, D277): one row per dispatched
 * tools/call message, written by McpAuthentication after dispatch.
 *
 * Append-only (created_at only, no updated_at — the alertable_errors
 * precedent for write-once observability tables). Arguments are stored
 * ONLY as a sha256 hash: the audit trail must never become a place where
 * sensitive argument values (tokens, free-text bodies) live in the clear.
 * T03 extends completeness (rejection outcomes, masking discipline);
 * this table shape is the foundation every later slice builds on.
 *
 * Retention semantics (M079 review, decided 2026-11-11): deleting a user
 * PURGES their MCP audit trail — users → mcp_clients (cascadeOnDelete) →
 * mcp_calls (cascadeOnDelete) — the deliberate GDPR-clean purge. The
 * nullable user_id + nullOnDelete below is defensive-only (it can never
 * fire ahead of the client cascade); unlike the FK-free alertable_errors
 * precedent, this table intentionally follows the user's deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mcp_client_id');
            $table->foreign('mcp_client_id')->references('id')->on('mcp_clients')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('tool');
            $table->string('arguments_hash', 64);
            $table->unsignedInteger('latency_ms');
            $table->timestampTz('created_at');
            $table->index(['mcp_client_id', 'created_at']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
    }
};
