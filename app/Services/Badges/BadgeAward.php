<?php

namespace App\Services\Badges;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // badge_user.evidence_id is a native uuid column: a non-uuid
        // key would crash the INSERT with a Postgres uuid-parse error.
        // Carry a null pointer instead — the audit row still records
        // the morph type and the award stands on its derivation.
        // (Defense-in-depth: current evaluators only propose uuid-keyed
        // evidence — SupportingMemberEvaluator resolves LocalSubscription
        // precisely to stay inside this constraint.)
        $evidenceId = is_scalar($key) ? (string) $key : null;

        if ($evidenceId !== null && ! Str::isUuid($evidenceId)) {
            Log::warning('badges.award.evidence_id_not_uuid', [
                'badge' => $badgeKey,
                'evidence_type' => $evidence->getMorphClass(),
            ]);

            $evidenceId = null;
        }

        return new self(
            $badgeKey,
            $tierKey,
            $evidence->getMorphClass(),
            $evidenceId,
        );
    }
}
