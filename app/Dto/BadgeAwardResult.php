<?php

namespace App\Dto;

/**
 * Outcome of one BadgeService award attempt (D186, spec §2.2).
 *
 * Backed enum (not an exception and not a DTO) because every outcome is a
 * normal domain state the caller switches on: evaluators re-run constantly,
 * "already held" is the overwhelmingly common result, and refusal reasons
 * (retired / dormant definition) are expected states, not errors. Genuine
 * programming errors — unknown badge key, unknown tier, manual award
 * without reason — throw InvalidArgumentException instead, keeping the
 * enum surface to states callers can meaningfully react to.
 *
 * Sibling of ParticipantResult / SyncResult in this directory.
 */
enum BadgeAwardResult: string
{
    /** New hold created (or a revoked hold re-awarded): badge_user row + `awarded` audit event written. */
    case Awarded = 'awarded';

    /** Existing hold promoted to a strictly higher tier: row updated in place + `upgraded` audit event. */
    case Upgraded = 'upgraded';

    /** Idempotent no-op: the user already holds this badge at the same or higher tier. No writes, no event. */
    case AlreadyHeld = 'already_held';

    /** Definition retired by an admin (is_active = false): new awards refused, existing holds untouched. */
    case RefusedInactive = 'refused_inactive';

    /** Definition dormant (is_dormant = true, signal not shipped yet): the engine holds the shape, refuses awards. */
    case RefusedDormant = 'refused_dormant';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
