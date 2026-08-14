<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the indexes the hottest read paths were seq-scanning on (audit, Aug 2026).
 *
 * All are created CONCURRENTLY: the platform runs live, and a plain
 * CREATE INDEX takes a SHARE lock that blocks writes for the build duration.
 * Concurrent builds are non-blocking but cannot run inside a transaction —
 * hence $withinTransaction = false (Laravel wraps migrations in transactions
 * on Postgres otherwise).
 *
 * Coverage (query shapes verified against call sites):
 *  - attendance_reports: zero secondary indexes existed. Game-detail renders,
 *    attendance resolution and nudge sweeps filter by (game_id, reported_id,
 *    status); user-scoped histories filter by reporter_id.
 *  - notifications: only the PK existed. Every bell/page read filters
 *    (notifiable_type, notifiable_id) on the fastest-growing table.
 *  - game_participants: (game_id, user_id) unique cannot serve user-only
 *    predicates. Dashboards/action-center filter (user_id, status).
 *  - games: discovery browse filters (status, date_time) on every page.
 *  - trgm mirrors for the `de` locale: the en trgm indexes on
 *    games/campaigns/events (name, description) had no German counterpart,
 *    so de-locale searches seq-scanned JSONB expressions.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * @var array<int, array{table: string, index: string, sql: string}>
     */
    private array $indexes = [
        ['table' => 'attendance_reports', 'index' => 'attendance_reports_game_reported_status_idx', 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendance_reports_game_reported_status_idx ON attendance_reports (game_id, reported_id, status)'],
        ['table' => 'attendance_reports', 'index' => 'attendance_reports_reporter_idx', 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS attendance_reports_reporter_idx ON attendance_reports (reporter_id)'],
        ['table' => 'notifications', 'index' => 'notifications_notifiable_idx', 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_notifiable_idx ON notifications (notifiable_type, notifiable_id)'],
        ['table' => 'game_participants', 'index' => 'game_participants_user_status_idx', 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS game_participants_user_status_idx ON game_participants (user_id, status)'],
        ['table' => 'games', 'index' => 'games_status_date_time_idx', 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS games_status_date_time_idx ON games (status, date_time)'],
        ['table' => 'games', 'index' => 'idx_games_name_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_games_name_de_trgm ON games USING gin (((name ->> 'de'::text)) gin_trgm_ops)"],
        ['table' => 'games', 'index' => 'idx_games_description_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_games_description_de_trgm ON games USING gin (((description ->> 'de'::text)) gin_trgm_ops)"],
        ['table' => 'campaigns', 'index' => 'idx_campaigns_name_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_campaigns_name_de_trgm ON campaigns USING gin (((name ->> 'de'::text)) gin_trgm_ops)"],
        ['table' => 'campaigns', 'index' => 'idx_campaigns_description_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_campaigns_description_de_trgm ON campaigns USING gin (((description ->> 'de'::text)) gin_trgm_ops)"],
        ['table' => 'events', 'index' => 'idx_events_name_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_name_de_trgm ON events USING gin (((name ->> 'de'::text)) gin_trgm_ops)"],
        ['table' => 'events', 'index' => 'idx_events_description_de_trgm', 'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_description_de_trgm ON events USING gin (((description ->> 'de'::text)) gin_trgm_ops)"],
    ];

    public function up(): void
    {
        foreach ($this->indexes as ['sql' => $sql]) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as ['index' => $index]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
