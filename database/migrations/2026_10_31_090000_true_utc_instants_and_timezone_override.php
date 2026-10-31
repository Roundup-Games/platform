<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R089 flip: the platform accidentally stored Berlin WALL TIME under the UTC
 * label (app.timezone = 'UTC', every writer interpreted datetime-local input
 * as UTC). This migration switches storage to TRUE UTC instants in one shot:
 *
 *  1. users.timezone_manually_set — manual profile override wins over every
 *     device-capture path (login, OAuth, beacon).
 *
 *  2. Every stored user-entered wall-clock column is reinterpreted: the
 *     stored value is Berlin wall time; the true instant is that wall time
 *     converted to UTC. Each row shifts by the Europe/Berlin offset IN
 *     EFFECT AT ITS OWN INSTANT (DST-correct, deterministic on re-run of
 *     the same data).
 *
 *     Columns: games.date_time, games.signup_cutoff_at,
 *              events.registration_opens_at, events.registration_closes_at,
 *              events.early_bird_deadline.
 *
 *     events.start_date/end_date are DATE columns (no time component) — a
 *     wall date is its own truth and is deliberately left untouched.
 *     System-written timestamps (reminder_sent_at, *_expires_at, …) were
 *     already true UTC instants (now()-based) and are untouched.
 *
 * Idempotency: the timezone_manually_set column doubles as the applied
 * marker — a second execution of up() returns before touching any data.
 *
 * PRE-LAUNCH one-way door (no production data): down() drops the flag
 * column but does NOT invert the data shift — the reinterpretation is not
 * uniquely invertible across DST folds and reverting would re-corrupt the
 * storage contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'timezone_manually_set')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('timezone_manually_set')->default(false)->after('timezone');
        });

        $this->shiftColumn('games', 'date_time', 'id');
        $this->shiftColumn('games', 'signup_cutoff_at', 'id');
        $this->shiftColumn('events', 'registration_opens_at', 'id');
        $this->shiftColumn('events', 'registration_closes_at', 'id');
        $this->shiftColumn('events', 'early_bird_deadline', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'timezone_manually_set')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone_manually_set');
        });
    }

    /**
     * Reinterpret one wall-clock column as Europe/Berlin wall time → UTC.
     *
     * Chunked, per-row (the shift depends on each row's own DST offset);
     * every row rewrites to the same value on a hypothetical re-run, and the
     * up() column guard prevents that re-run from happening anyway.
     */
    private function shiftColumn(string $table, string $column, string $key): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->orderBy($key)
            ->chunkById(500, function ($rows) use ($table, $column, $key): void {
                foreach ($rows as $row) {
                    $raw = $row->{$column};
                    if (! is_string($raw) && ! $raw instanceof DateTimeInterface) {
                        continue;
                    }

                    $shifted = Carbon::parse((string) $raw, community_timezone())
                        ->setTimezone('UTC')
                        ->format('Y-m-d H:i:s');

                    DB::table($table)
                        ->where($key, $row->{$key})
                        ->where($column, $raw)
                        ->update([$column => $shifted]);
                }
            }, $key);
    }
};
