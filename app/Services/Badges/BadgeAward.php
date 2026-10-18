<?php

namespace App\Services\Badges;

use Illuminate\Database\Eloquent\Model;

/**
 * An evaluator's proposed award (spec §2.2) — a pure value, never a write.
 *
 * Carries which badge (registry key), which tier (null = tierless badge or
 * base award), and the evidence pointer: the record that proves the award
 * (the subscription, attendance report, charter, campaign…). Evidence is
 * carried either as an Eloquent model (morph alias + key extracted here,
 * so the model never needs to survive past the call) or as a raw
 * type/id pair for callers that only hold identifiers (sweeps, backfills).
 *
 * BadgeService consumes this DTO: it owns the idempotent badge_user
 * write, the tier-upgrade decision and the badge_events audit row that
 * records the same evidence pointer.
 */
final readonly class BadgeAward
{
    public function __construct(
        public string $badgeKey,
        public ?string $tierKey,
        public ?string $evidenceType,
        public ?string $evidenceId,
    ) {}

    /**
     * Award with evidence as an Eloquent model. The morph alias is read
     * via getMorphClass() so the persisted pointer honors
     * Relation::enforceMorphMap() (D170), not the class basename.
     */
    public static function for(string $badgeKey, ?string $tierKey = null, ?Model $evidence = null): self
    {
        if ($evidence === null) {
            return new self($badgeKey, $tierKey, null, null);
        }

        /** @var mixed $key */
        $key = $evidence->getKey();

        return new self(
            $badgeKey,
            $tierKey,
            $evidence->getMorphClass(),
            is_scalar($key) ? (string) $key : null,
        );
    }

    /**
     * Award with a raw morph type/id evidence pair, for callers that hold
     * identifiers but no hydrated model (sweeps, backfills).
     */
    public static function fromEvidencePair(string $badgeKey, ?string $tierKey, ?string $evidenceType, ?string $evidenceId): self
    {
        return new self($badgeKey, $tierKey, $evidenceType, $evidenceId);
    }
}
