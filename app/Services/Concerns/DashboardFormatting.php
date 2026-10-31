<?php

namespace App\Services\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared formatting and data-loading helpers used across dashboard services.
 *
 * Extracted from duplicated private methods in DashboardDiscoveryService,
 * DashboardScheduleService, ActionCenterService, and DashboardNewcomerService.
 */
trait DashboardFormatting
{
    /**
     * Format a datetime as a human-readable relative time string.
     *
     * Returns localised strings like "Today at 7 PM", "Tomorrow at 3 PM",
     * "Fri at 6 PM", or "Jan 15 at 2 PM".
     *
     * Community wall clock (R089): the today/tomorrow/this-week buckets and
     * the rendered hour resolve on the Europe/Berlin calendar — the pinned
     * zone for every dashboard bucket (deterministic cohorts, not per-viewer
     * drifting labels).
     */
    protected function formatRelativeTime(?Carbon $dateTime): string
    {
        if ($dateTime === null) {
            return '';
        }

        $dateTime = $dateTime->copy()->setTimezone(community_timezone());
        $now = community_now();
        $time = $dateTime->format('g A');

        if ($dateTime->isToday()) {
            return __('profile.dashboard_relative_today', ['time' => $time]);
        }

        if ($dateTime->isTomorrow()) {
            return __('profile.dashboard_relative_tomorrow', ['time' => $time]);
        }

        if ($dateTime->isSameWeek($now) && $dateTime->greaterThan($now)) {
            return $dateTime->format('D').' '.__('profile.dashboard_relative_at', ['time' => $time]);
        }

        return $dateTime->format('M j').' '.__('profile.dashboard_relative_at', ['time' => $time]);
    }

    /**
     * Bulk-load game system preference IDs for a set of users.
     *
     * @param  array<int, mixed>  $userIds
     * @return array<int|string, mixed>
     */
    protected function bulkLoadGameSystemPreferences(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('user_game_system_preferences')
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'game_system_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('game_system_id')->toArray())
            ->toArray();
    }
}
