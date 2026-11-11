<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP consumer identity (M079/S01/T02, D277).
 *
 * One row per issued agent credential: the consumer an mcp_calls audit row
 * points at. The Sanctum personal access token carries the abilities; this
 * row carries the identity and lifecycle — revoked_at kills the token's
 * access at the middleware layer even if the token row itself lingers.
 *
 * Linkage is deliberately non-invasive: mcp_clients holds the PAT id
 * (Sanctum's core tables are untouched). The unique index on the column
 * enforces the 1:1 direction (one token belongs to at most one client);
 * nullOnDelete keeps revoked history visible after the token row is
 * deleted on revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('name');
            // T03 keys the per-consumer rate limiter on this tier.
            $table->string('rate_limit_tier', 32)->default('standard');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->foreign('personal_access_token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
            $table->unique(['personal_access_token_id']);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
