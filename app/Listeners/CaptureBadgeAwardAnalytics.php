<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\Badges\Events\BadgeUpgraded;
use App\Services\PostHogAnalytics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The analytics half of the badge award moment (M069 spec §2.2): one
 * queued listener behind BadgeAwarded AND BadgeUpgraded capturing
 * `badge_awarded` / `badge_upgraded` with badge, tier and source
 * properties, pseudonymized to the opaque user id by PostHogAnalytics.
 * capture() already no-ops without consent or when PostHog is disabled,
 * so this listener needs no gating of its own.
 *
 * Registered for award/upgrade only (AppServiceProvider) — lapses and
 * revocations are deliberately quiet (spec §4): no event, no capture.
 * A user deleted between dispatch and delivery is skipped quietly;
 * there is no one left to attribute the moment to.
 */
class CaptureBadgeAwardAnalytics implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly PostHogAnalytics $analytics,
    ) {}

    public function handle(BadgeAwarded|BadgeUpgraded $event): void
    {
        $user = User::query()->whereKey($event->userId)->first();

        if ($user === null) {
            return;
        }

        $this->analytics->capture(
            $user,
            $event instanceof BadgeUpgraded ? 'badge_upgraded' : 'badge_awarded',
            [
                'badge' => $event->badgeKey,
                'tier' => $event->tier,
                'source' => $event->source->value,
            ],
        );
    }
}
