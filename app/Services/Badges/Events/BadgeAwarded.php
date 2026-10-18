<?php

namespace App\Services\Badges\Events;

use App\Enums\BadgeSource;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A badge was newly awarded (or re-awarded after revocation) (D186, spec §2.2).
 *
 * Dispatched by BadgeService AFTER the awarding transaction commits, so S02
 * listeners (NotificationService, PostHog `badge_awarded`) always observe
 * committed state. No listeners are registered in S01 — this class is the
 * seam, not the wiring.
 *
 * Carries identifiers and the evidence pointer only (morph alias + id, per
 * the D170 morph map), never hydrated models: the event must stay cheap to
 * fake, queue and serialize.
 */
final readonly class BadgeAwarded
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
