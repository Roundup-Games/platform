<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds the partial unique index backing the duplicate-registration guard
 * (M063/S02 ticket payment closure).
 *
 * Index: event_registrations_event_user_active_unique on (event_id, user_id)
 * WHERE status <> 'cancelled'
 *
 * Semantics: at most one ACTIVE (pending/confirmed/...) registration per
 * (event, user) at the database level. Cancelled rows are exempt — a user
 * who cancels may register again, leaving a chain of cancelled rows behind.
 *
 * The application-level exists() check in RegisterForEvent::register()
 * narrows the race, but the serialized event-row lock does not cover other
 * insert surfaces; this index makes the invariant unconditional and makes
 * the component's QueryException catch (duplicate handling) reachable.
 *
 * Driver support: Postgres (production) and SQLite (>= 3.8.0) both support
 * partial unique indexes natively, so no fallback is required. Note the
 * index is created with raw DDL instead of the Blueprint fluent API:
 * Laravel v13's compileIndex() silently ignores a ->where() modifier on
 * index definitions (no driver compiles partial predicates), which would
 * create a FULL unique index and break re-registration after cancel. The
 * alternative — a full unique index on (event_id, user_id, status) — was
 * rejected: it would limit a user to exactly one cancelled row per event.
 *
 * Pre-index dedupe: any (event_id, user_id) with more than one non-cancelled
 * row would abort index creation. Newer duplicates are demoted to cancelled
 * (first registration wins, matching the user-facing "already registered"
 * semantics). Pre-flight check on the dev database (2026-10-06) found zero
 * duplicate groups, so this loop is a safety net for production only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->demoteDuplicateActiveRegistrations();

        // Raw DDL: partial index predicates are not compiled by Laravel's
        // schema builder (see class docblock). Identical syntax is valid on
        // Postgres and SQLite.
        DB::statement(
            'CREATE UNIQUE INDEX event_registrations_event_user_active_unique'
            .' ON event_registrations (event_id, user_id)'
            ." WHERE status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        // DROP INDEX (not ALTER TABLE DROP CONSTRAINT): this is a standalone
        // unique INDEX, not a table constraint. Valid on both drivers.
        DB::statement('DROP INDEX IF EXISTS event_registrations_event_user_active_unique');
    }

    /**
     * Demote all but the oldest non-cancelled registration per (event, user)
     * to cancelled, so the partial index can be created. Driver-portable
     * (query-builder only; no UPDATE...FROM CTE, which SQLite does not
     * support).
     *
     * @return int Number of registrations demoted.
     */
    private function demoteDuplicateActiveRegistrations(): int
    {
        $duplicateGroups = DB::table('event_registrations')
            ->select('event_id', 'user_id', DB::raw('count(*) as duplicates'))
            ->whereNot('status', 'cancelled')
            ->groupBy('event_id', 'user_id')
            // HAVING cannot reference the SELECT alias on Postgres (only
            // MySQL allows it) — reference the aggregate directly.
            ->havingRaw('count(*) > 1')
            ->get();

        $demoted = 0;

        foreach ($duplicateGroups as $group) {
            $keepId = DB::table('event_registrations')
                ->where('event_id', $group->event_id)
                ->where('user_id', $group->user_id)
                ->whereNot('status', 'cancelled')
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            $demoted += DB::table('event_registrations')
                ->where('event_id', $group->event_id)
                ->where('user_id', $group->user_id)
                ->whereNot('status', 'cancelled')
                ->whereNot('id', $keepId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if ($demoted > 0) {
            Log::warning('event_registrations unique index migration demoted duplicate active registrations', [
                'groups' => $duplicateGroups->count(),
                'demoted_rows' => $demoted,
                'reason' => 'pre-index dedupe before event_registrations_event_user_active_unique',
            ]);
        }

        return $demoted;
    }
};
