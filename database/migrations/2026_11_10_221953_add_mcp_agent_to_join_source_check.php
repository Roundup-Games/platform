<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the `join_source` CHECK constraints on game_participants and
 * campaign_participants to accept the new `mcp_agent` value (M079/S02/T02).
 *
 * `join_source` is a nullable varchar column cast to the {@see JoinSource}
 * enum on the models, BUT PostgreSQL enforces the allowed values with a
 * native CHECK constraint (not a PHP enum), so adding a case to the enum
 * is code-only for the cast — it ALSO needs this migration or the DB
 * rejects the row. Mirrors the precedent migrations for `short_link`,
 * `email_invite`, and `discord`, which updated BOTH the game and campaign
 * participant tables (the enum is shared).
 *
 * Agent-surface joins (games.join) are game-only in M079, but the shared
 * enum case is added to both constraints to keep them consistent and to
 * match the established pattern where every JoinSource case is valid on
 * both tables.
 *
 * PostgreSQL cannot ALTER a CHECK constraint in place, so this drops and
 * recreates each one. `DROP ... IF EXISTS` makes the migration safe to
 * re-run and tolerant of a baseline that may or may not already carry
 * the constraint.
 */
return new class extends Migration
{
    /**
     * The full set of allowed join_source values, in declaration order,
     * with the new `mcp_agent` case appended. Kept as a single constant
     * so the up/down expressions stay in lockstep and cannot drift.
     */
    private const VALUES_WITH_MCP_AGENT = "'friend_invite', 'share_link', 'application', 'email_invite', 'short_link', 'discord', 'mcp_agent'";

    private const VALUES_WITHOUT_MCP_AGENT = "'friend_invite', 'share_link', 'application', 'email_invite', 'short_link', 'discord'";

    public function up(): void
    {
        $this->recreateConstraint('game_participants', self::VALUES_WITH_MCP_AGENT);
        $this->recreateConstraint('campaign_participants', self::VALUES_WITH_MCP_AGENT);
    }

    public function down(): void
    {
        $this->recreateConstraint('game_participants', self::VALUES_WITHOUT_MCP_AGENT);
        $this->recreateConstraint('campaign_participants', self::VALUES_WITHOUT_MCP_AGENT);
    }

    /**
     * Drop the existing join_source CHECK constraint (if present) and
     * recreate it with the supplied value list.
     *
     * CHECK constraints allow NULL by default (the expression evaluates
     * to NULL, not false, for a NULL column), so the nullable join_source
     * column keeps accepting NULL without an explicit IS NULL clause.
     */
    private function recreateConstraint(string $table, string $valuesList): void
    {
        $constraint = $table.'_join_source_check';

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint}"
            ." CHECK (join_source = ANY (ARRAY[{$valuesList}]::varchar[]))"
        );
    }
};
