<?php

namespace App\Services\Badges\Events;

use App\Enums\BadgeSource;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A held badge was revoked by an admin (D186, spec §2.2).
 *
 * Dispatched by BadgeService AFTER the revocation transaction commits.
 * No listeners are registered in S01 — this class is the seam, not the
 * wiring. S02 surfaces revocations to the affected user; revocation is
 * reversible (re-award clears the stamps) and milestones are never
 * re-taken, so listeners must treat this as "hold withdrawn", not
 * "history erased" — the badge_events audit trail is append-only.
 *
 * Carries the tier the user held at revocation time plus the mandatory
 * admin reason (BadgeService::revoke() refuses blank reasons).
 */
final readonly class BadgeRevoked
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $badgeKey,
        public ?string $tier,
        public BadgeSource $source,
        public ?string $evidenceType,
        public ?string $evidenceId,
        public string $reason,
    ) {}
}
