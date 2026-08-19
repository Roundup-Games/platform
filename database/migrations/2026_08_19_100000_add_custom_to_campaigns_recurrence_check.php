<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the `campaigns.recurrence` CHECK constraint to accept the `custom`
 * value ("irregular" cadence).
 *
 * The public create-campaign form has offered `custom` since M001/S03, but the
 * constraint only admitted weekly/bi-weekly/monthly, so selecting it passed
 * Livewire validation and then failed at the database (500). The enum surface
 * (App\Enums\Recurrence) and RecurrenceService already model `custom`
 * deliberately — per ADR MEM809 recurrence is human intention, and an
 * irregular campaign is exactly "no machine cadence": no plan-ahead nudges,
 * no suggested dates (RecurrenceService::shouldNudge / nextSuggestedDateTime
 * return false/null for it).
 *
 * Widening a CHECK constraint can never invalidate existing rows, and no
 * `custom` rows can exist (the constraint rejected them), so no backfill is
 * needed. Follows the drop-and-recreate precedent of the join_source CHECK
 * migration (2026_07_22_210000).
 */
return new class extends Migration
{
    /**
     * The full set of allowed recurrence values, with `custom` appended.
     * Kept as constants so the up/down expressions stay in lockstep.
     */
    private const VALUES_WITH_CUSTOM = "'weekly', 'bi-weekly', 'monthly', 'custom'";

    private const VALUES_WITHOUT_CUSTOM = "'weekly', 'bi-weekly', 'monthly'";

    public function up(): void
    {
        $this->recreateConstraint(self::VALUES_WITH_CUSTOM);
    }

    public function down(): void
    {
        $this->recreateConstraint(self::VALUES_WITHOUT_CUSTOM);
    }

    /**
     * Drop the existing recurrence CHECK constraint (if present) and recreate
     * it with the supplied value list.
     *
     * The column is NOT NULL, so no NULL handling is needed in the CHECK.
     */
    private function recreateConstraint(string $valuesList): void
    {
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_recurrence_check');
        DB::statement(
            'ALTER TABLE campaigns ADD CONSTRAINT campaigns_recurrence_check'
            ." CHECK (recurrence = ANY (ARRAY[{$valuesList}]::varchar[]))"
        );
    }
};
