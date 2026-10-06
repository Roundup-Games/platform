<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the team-era registration machinery from events and
 * event_registrations (M063/S01 community vocabulary review).
 *
 * The MVP is individual-only: one person, one registration, paid or free.
 * Team registration, rosters, divisions, per-team size limits, and the
 * dead logo_url/banner_url string columns (superseded by the Spatie
 * media library logo/banner collections) all go.
 *
 * Pre-flight data check (2026-10-06, dev database):
 *   event_registrations.team_id IS NOT NULL                  -> 0 rows
 *   event_registrations.roster IS NOT NULL                   -> 0 rows
 *   event_registrations.division IS NOT NULL                 -> 0 rows
 *   event_registrations.registration_type <> 'individual'    -> 0 rows
 *   events.divisions IS NOT NULL                             -> 0 rows
 *   events.registration_type <> 'individual'                 -> 0 rows
 * All zero on dev, so no archive tables were exported. Production MUST
 * repeat this check before deploying; if any count is non-zero, export
 * the affected rows to an archive table first (e.g.
 * event_registrations_team_archive) and note it here.
 *
 * Dropping the columns also drops their dependent CHECK constraints
 * (events_registration_type_check, event_registrations_registration_type_check)
 * and the event_registrations_team_id_foreign FK.
 *
 * down() restores the original structure but is lossy by design: data that
 * lived in the dropped columns (team links, rosters, divisions, per-team
 * fees) cannot be recovered. Re-added registration_type columns backfill
 * existing rows with 'team'/'individual' defaults respectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->dropForeign(['team_id']);
                $table->dropColumn(['team_id', 'roster', 'division', 'registration_type']);
            });

            Schema::table('events', function (Blueprint $table): void {
                $table->dropColumn([
                    'registration_type',
                    'max_teams',
                    'min_players_per_team',
                    'max_players_per_team',
                    'team_registration_fee',
                    'divisions',
                    'logo_url',
                    'banner_url',
                ]);
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('events', function (Blueprint $table): void {
                $table->string('registration_type')->default('team');
                $table->integer('max_teams')->nullable();
                $table->integer('min_players_per_team')->default(7);
                $table->integer('max_players_per_team')->default(21);
                $table->integer('team_registration_fee')->default(0);
                $table->json('divisions')->nullable();
                $table->string('logo_url')->nullable();
                $table->string('banner_url')->nullable();
            });

            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->uuid('team_id')->nullable();
                $table->json('roster')->nullable();
                $table->string('division', 100)->nullable();
                $table->string('registration_type')->default('individual');
                $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            });

            // Restore the CHECK constraints that up() dropped with the columns.
            DB::statement(
                'ALTER TABLE events ADD CONSTRAINT events_registration_type_check'
                ." CHECK (registration_type = ANY (ARRAY['team', 'individual', 'both']::varchar[]))"
            );
            DB::statement(
                'ALTER TABLE event_registrations ADD CONSTRAINT event_registrations_registration_type_check'
                ." CHECK (registration_type = ANY (ARRAY['team', 'individual']::varchar[]))"
            );
        });
    }
};
