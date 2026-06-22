<?php

namespace App\Dto\Dashboard;

/**
 * Dashboard sections and derived data rendered in BOTH newcomer and established modes.
 *
 * @see AssembledDashboard::toViewProps()
 */
final readonly class SharedDashboard
{
    /**
     * @param  string  $mode  Resolved Dashboard mode ('newcomer' | 'established').
     * @param  array<string, mixed>  $smartPrompt  DashboardSmartPromptService output.
     * @param  array<string, mixed>  $contributions  Dashboard section: contributions.
     * @param  array<string, mixed>  $weekData  Dashboard section: week.
     */
    public function __construct(
        public string $mode,
        public int $unreadNotificationsCount,
        public array $smartPrompt,
        public array $contributions,
        public array $weekData,
    ) {}
}
