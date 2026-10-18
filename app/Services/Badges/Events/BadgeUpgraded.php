<?php

namespace App\Services\Badges\Events;

use App\Enums\BadgeSource;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A held badge was promoted to a strictly higher tier (D186, spec §2.2).
 *
 * Dispatched by BadgeService AFTER the upgrading transaction commits, so
 * S02 listeners (NotificationService, PostHog) always observe committed
 * state. No listeners are registered in S01 — this class is the seam,
 * not the wiring.
 *
 * Same identifier-only payload discipline as BadgeAwarded: user id,
 * badge key, the NEW tier, source and the evidence pointer for the
 * upgrade (morph alias + id, per the D170 morph map).
 */
final readonly class BadgeUpgraded
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $badgeKey,
        public ?string $tier,
        public BadgeSource $source,
        public ?string $evidenceType,
        public ?string $evidenceId,
    ) {}
}
