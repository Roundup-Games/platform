<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the legacy `events.type` CHECK constraint with the community
 * vocabulary from App\Enums\EventType and backfills existing rows.
 *
 * Old values: tournament|league|camp|clinic|social|other
 * New values: game_day|social|convention|other
 *
 * Backfill mapping (community vocabulary review, M063/S01):
 *   tournament|league -> game_day  (competitive gatherings fold into game days)
 *   camp|clinic       -> other     (structured sessions have no MVP vocabulary)
 *   social|other      -> unchanged
 *
 * The Filament EventResource offered convention/game_day while the CHECK only
 * admitted the six legacy values, so admin inserts of those options passed
 * form validation and then crashed at the database. This migration aligns the
 * constraint with the enum so EventType is the single source of truth for
 * type values.
 *
 * The column default moves with the constraint: the legacy default
 * 'tournament' would violate the new CHECK on any insert that omits type,
 * so it becomes 'game_day' (and back on down).
 *
 * Follows the drop-and-recreate precedent of the campaigns recurrence CHECK
 * migration (2026_08_19_100000). down() is lossy by design: game_day and
 * convention rows remap back to tournament so the legacy CHECK validates.
 */
return new class extends Migration
{
    /**
     * The full value lists for the constraint, kept as constants so the
     * up/down expressions stay in lockstep.
     */
    private const OLD_VALUES = "'tournament', 'league', 'camp', 'clinic', 'social', 'other'";

    private const NEW_VALUES = "'game_day', 'social', 'convention', 'other'";

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('events')->whereIn('type', ['tournament', 'league'])->update(['type' => 'game_day']);
            DB::table('events')->whereIn('type', ['camp', 'clinic'])->update(['type' => 'other']);

            $this->recreateConstraint(self::NEW_VALUES);
            DB::statement("ALTER TABLE events ALTER COLUMN type SET DEFAULT 'game_day'");
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // Lossy reverse map — a game_day could originally have been a
            // tournament or a league; both fold back to tournament.
            DB::table('events')->whereIn('type', ['game_day', 'convention'])->update(['type' => 'tournament']);

            $this->recreateConstraint(self::OLD_VALUES);
            DB::statement("ALTER TABLE events ALTER COLUMN type SET DEFAULT 'tournament'");
        });
    }

    /**
     * Drop the existing type CHECK constraint (if present) and recreate it
     * with the supplied value list.
     *
     * The column is NOT NULL, so no NULL handling is needed in the CHECK.
     */
    private function recreateConstraint(string $valuesList): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_type_check');
        DB::statement(
            'ALTER TABLE events ADD CONSTRAINT events_type_check'
            ." CHECK (type = ANY (ARRAY[{$valuesList}]::varchar[]))"
        );
    }
};
