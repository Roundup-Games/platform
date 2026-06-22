<?php

namespace App\Dto\Dashboard;

use Illuminate\Support\Collection;

/**
 * Dashboard sections and derived data rendered in BOTH newcomer and established modes.
 *
 * The always-stub values (`gamesThisWeek`, `gamesThisWeekCount`) live here because
 * the current view contract emits them as stubs in both modes — they are preserved
 * verbatim through the Phase 1 view-model bridge so the Blade partials are untouched.
 *
 * @see AssembledDashboard::toViewProps()
 */
final readonly class SharedDashboard
{
    /**
     * @param  array<string, mixed>  $smartPrompt  DashboardSmartPromptService output.
     * @param  array<string, mixed>  $contributions  Dashboard section: contributions.
     * @param  array<string, mixed>  $weekData  Dashboard section: week.
     * @param  Collection<int, mixed>  $gamesThisWeek  Always empty today (stub preserved for view compatibility).
     */
    public function __construct(
        public string $mode,
        public int $unreadNotificationsCount,
        public array $smartPrompt,
        public array $contributions,
        public array $weekData,
        public Collection $gamesThisWeek,
        public int $gamesThisWeekCount,
    ) {}
}
