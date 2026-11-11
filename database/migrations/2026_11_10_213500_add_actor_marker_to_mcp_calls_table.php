<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-actor marker on the MCP audit trail (M079/S03/T02): mcp_calls
 * gains an actor column, stamped 'agent' by McpAuthentication.
 *
 * The table only ever receives rows from the MCP surface, so every row is
 * an agent-actor call — the marker makes that provenance explicit and
 * queryable instead of a table-level inference, which is what the
 * agent-actor audit contract (T02) asserts: a write through the agent
 * surface differs observably from the same write on the web path (no
 * mcp_calls row, no actor marker) even though both hit the identical
 * domain service and notification fan-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->string('actor', 16)->default('agent')->after('tool');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->dropColumn('actor');
        });
    }
};
