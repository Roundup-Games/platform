<?php

namespace App\Dto\Dashboard;

/**
 * Dashboard sections rendered only in newcomer mode.
 *
 * @see AssembledDashboard
 */
final readonly class NewcomerDashboard
{
    /**
     * @param  array<int, array{label: string, url: string, style: string, icon: string}>  $quickActions
     * @param  array<string, mixed>  $newcomerWelcome  Dashboard section: newcomer_welcome.
     * @param  array<string, mixed>  $preferenceMatches  Dashboard section: newcomer_matches (geohash-tracked).
     * @param  array<string, mixed>  $progressTracker  Dashboard section: progress_tracker.
     * @param  array<string, mixed>  $nearbyPeople  Dashboard section: nearby_people (geohash-tracked).
     */
    public function __construct(
        public array $quickActions,
        public array $newcomerWelcome,
        public array $preferenceMatches,
        public array $progressTracker,
        public array $nearbyPeople,
    ) {}
}
