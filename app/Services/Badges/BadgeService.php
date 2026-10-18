<?php

namespace App\Services\Badges;

use App\Dto\BadgeAwardResult;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\Badges\Events\BadgeRevoked;
use App\Services\Badges\Events\BadgeUpgraded;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The badge engine's write side (D186, spec §2.2): the audited pipeline
 * every award, upgrade and revocation flows through.
 *
 * Guarantees:
 *
 * - **Idempotent awards** — the unique(user_id, badge_definition_id)
 *   constraint is the backstop; a concurrent duplicate insert surfaces as
 *   SQLSTATE 23505 and is treated as AlreadyHeld, never as a second row.
 * - **Tier upgrades in place** — a strictly higher tier UPDATEs the held
 *   row (threshold ordering per the definition's tiers JSON); same or
 *   lower (null = untiered = lowest) is a no-op.
 * - **Reversible revocation and lapse** — revoke stamps revoked_at/
 *   revoked_reason, lapse stamps lapsed_at (spec principle 4: lapse
 *   honestly, never vanish); a re-award clears the stamps and audits
 *   Requalified (lapsed) or Awarded (revoked). Milestones are never
 *   re-taken: a re-awarded row keeps first_awarded_at.
 * - **Append-only audit trail** — every state change writes exactly one
 *   badge_events row with actor, source, reason and the evidence pointer.
 * - **Domain events after commit** — BadgeAwarded/BadgeUpgraded/
 *   BadgeRevoked are dispatched AFTER the transaction returns, so S02
 *   listeners (NotificationService, PostHog) only ever observe committed
 *   state. No listeners exist in S01; these dispatches are the seam.
 */
class BadgeService
{
    /**
     * Award a badge (or a higher tier of it) to a user, idempotently.
     *
     * @param  string  $key  Registry key, e.g. 'debut' (must exist in badge_definitions —
     *                       run `php artisan badges:sync`; a missing row is a programming error).
     * @param  string|null  $tier  Tier key validated against the definition's tiers JSON
     *                             (null = untiered / base award).
     * @param  Model|null  $evidence  The record that justifies the award (subscription,
     *                                attendance report, charter…); its morph alias per the
     *                                D170 map and key are extracted via BadgeAward::for().
     * @param  BadgeSource  $source  Automatic (evaluator/sweep) or Manual (admin; requires $reason).
     * @param  User|null  $actor  Acting admin for manual operations; null for system/evaluator.
     * @param  string|null  $reason  Mandatory for manual awards; recorded on the audit row.
     *
     * @throws InvalidArgumentException Unknown badge key; unknown tier key; tier on an
     *                                  untiered definition; manual source without a reason.
     */
    public function award(
        User $user,
        string $key,
        ?string $tier = null,
        ?Model $evidence = null,
        BadgeSource $source = BadgeSource::Automatic,
        ?User $actor = null,
        ?string $reason = null,
    ): BadgeAwardResult {
        $definition = BadgeDefinition::query()->where('key', $key)->first();

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown badge key [{$key}] — is the registry synced? Run `php artisan badges:sync`.");
        }

        // Admin retirement: refuse NEW awards, existing holds stay untouched.
        if (! $definition->is_active) {
            return BadgeAwardResult::RefusedInactive;
        }

        // Registered shape whose signal has not shipped yet (D189/D190).
        if ($definition->is_dormant) {
            return BadgeAwardResult::RefusedDormant;
        }

        // Service-level manual-discipline enforcement (full admin flow is S03).
        if ($source === BadgeSource::Manual && blank($reason)) {
            throw new InvalidArgumentException("Manual award of [{$key}] requires a reason.");
        }

        $this->assertTierAllowed($definition, $tier);

        // The evidence pointer (morph alias + id per D170) is extracted the
        // same way every caller would extract it — through the award DTO.
        $award = BadgeAward::for($key, $tier, $evidence);
        $userId = (string) $user->id;

        try {
            /** @var array{0: BadgeAwardResult, 1: BadgeAwarded|BadgeUpgraded|null} $outcome */
            $outcome = DB::transaction(function () use ($user, $definition, $award, $source, $actor, $reason, $userId): array {
                $held = BadgeUser::query()
                    ->whereBelongsTo($user)
                    ->whereBelongsTo($definition)
                    ->lockForUpdate()
                    ->first();

                $now = now();

                if ($held === null) {
                    $held = $this->createHold($user, $definition, $award, $source, $now);
                    $this->recordEvent($held, BadgeEventAction::Awarded, $source, $actor, $reason, $award);

                    return [BadgeAwardResult::Awarded, $this->awardedEvent($userId, $award, $source)];
                }

                // Revocation and lapse are both reversible: clear the
                // stamps and treat the badge as newly (re-)awarded.
                // Milestones are never re-taken — first_awarded_at
                // survives below. A revoked row is simply re-awarded
                // (Awarded audit); a lapsed one re-qualifies
                // (Requalified) — revocation supersedes lapse either way.
                $wasRevoked = $held->revoked_at !== null;

                if ($wasRevoked || $held->lapsed_at !== null) {
                    $held->fill([
                        'revoked_at' => null,
                        'revoked_reason' => null,
                        'lapsed_at' => null,
                        'tier' => $award->tierKey,
                        'source' => $source,
                        'last_awarded_at' => $now,
                        'evidence_type' => $award->evidenceType,
                        'evidence_id' => $award->evidenceId,
                    ])->save();

                    $this->recordEvent(
                        $held,
                        $wasRevoked ? BadgeEventAction::Awarded : BadgeEventAction::Requalified,
                        $source,
                        $actor,
                        $reason,
                        $award,
                    );

                    return [BadgeAwardResult::Awarded, $this->awardedEvent($userId, $award, $source)];
                }

                $tiers = $this->tierThresholds($definition);
                $heldRank = $this->tierRank($held->tier, $tiers);
                $newRank = $this->tierRank($award->tierKey, $tiers);

                // Same or lower tier (null = untiered = lowest): idempotent
                // no-op — no writes, no event, nothing for S02 to hear about.
                if ($newRank <= $heldRank) {
                    return [BadgeAwardResult::AlreadyHeld, null];
                }

                $held->fill([
                    'tier' => $award->tierKey,
                    'last_awarded_at' => $now,
                    'evidence_type' => $award->evidenceType,
                    'evidence_id' => $award->evidenceId,
                ])->save();

                $this->recordEvent($held, BadgeEventAction::Upgraded, $source, $actor, $reason, $award);

                return [BadgeAwardResult::Upgraded, new BadgeUpgraded(
                    $userId,
                    $award->badgeKey,
                    $award->tierKey,
                    $source,
                    $award->evidenceType,
                    $award->evidenceId,
                )];
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrency backstop: a racing insert won the unique
            // (user_id, badge_definition_id) constraint — the user holds
            // the badge, which is exactly what AlreadyHeld means.
            return BadgeAwardResult::AlreadyHeld;
        }

        if ($outcome[1] !== null) {
            event($outcome[1]);
        }

        return $outcome[0];
    }

    /**
     * Revoke a held badge (admin corrective action), recording the reason.
     *
     * Reversible: a later award() clears the stamps. The row is never
     * deleted — "last held" display and the audit trail keep their
     * referent (spec principle 4).
     *
     * @throws InvalidArgumentException Unknown badge key, or a blank reason.
     */
    public function revoke(User $user, string $key, string $reason, ?User $actor = null): bool
    {
        if (blank($reason)) {
            throw new InvalidArgumentException("Revoking [{$key}] requires a reason.");
        }

        $definition = BadgeDefinition::query()->where('key', $key)->first();

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown badge key [{$key}] — is the registry synced? Run `php artisan badges:sync`.");
        }

        $userId = (string) $user->id;

        /** @var array{0: bool, 1: BadgeRevoked|null} $outcome */
        $outcome = DB::transaction(function () use ($user, $definition, $reason, $actor, $userId): array {
            $held = BadgeUser::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($definition)
                ->lockForUpdate()
                ->first();

            // Nothing held, or already revoked — nothing to revoke.
            if ($held === null || $held->revoked_at !== null) {
                return [false, null];
            }

            $tierAtRevocation = $held->tier;
            $evidenceType = $held->evidence_type;
            $evidenceId = $held->evidence_id;

            $held->fill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ])->save();

            $this->recordEvent($held, BadgeEventAction::Revoked, BadgeSource::Manual, $actor, $reason, null);

            return [true, new BadgeRevoked(
                $userId,
                $definition->key,
                $tierAtRevocation,
                BadgeSource::Manual,
                $evidenceType,
                $evidenceId,
                $reason,
            )];
        });

        if ($outcome[1] !== null) {
            event($outcome[1]);
        }

        return $outcome[0];
    }

    /**
     * Lapse a held recurring badge quietly (membership ended, streak
     * broken) — the evaluator-side counterpart of revoke().
     *
     * Stamps lapsed_at instead of deleting: the row keeps its history
     * and the audit trail its referent (spec principle 4), and a later
     * award() re-qualifies in place (Requalified audit action).
     *
     * Quiet by design (spec §4): unlike revoke(), no domain event is
     * dispatched — lapses are routine maintenance, not moments.
     * Admin revocation supersedes lapse: a revoked row is skipped
     * (false), never lapsed.
     *
     * @param  string  $reason  Cause recorded on the audit row (e.g.
     *                          'membership_ended', 'streak_broken').
     *
     * @throws InvalidArgumentException Unknown badge key, or a blank reason.
     */
    public function lapse(User $user, string $key, string $reason): bool
    {
        if (blank($reason)) {
            throw new InvalidArgumentException("Lapsing [{$key}] requires a reason.");
        }

        $definition = BadgeDefinition::query()->where('key', $key)->first();

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown badge key [{$key}] — is the registry synced? Run `php artisan badges:sync`.");
        }

        return DB::transaction(function () use ($user, $definition, $reason): bool {
            $held = BadgeUser::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($definition)
                ->lockForUpdate()
                ->first();

            // Nothing held, already lapsed, or admin-revoked (revocation
            // supersedes lapse — there is no active hold to lapse).
            if ($held === null || $held->lapsed_at !== null || $held->revoked_at !== null) {
                return false;
            }

            $held->fill(['lapsed_at' => now()])->save();

            // No evidence pointer at lapse time — the award's evidence
            // stays on the row; source is always Automatic (evaluator
            // sweep), actor always null (system).
            $this->recordEvent($held, BadgeEventAction::Lapsed, BadgeSource::Automatic, null, $reason, null);

            return true;
        });
    }

    // ── Internals ──────────────────────────────────────

    private function assertTierAllowed(BadgeDefinition $definition, ?string $tier): void
    {
        $tiers = $this->tierThresholds($definition);

        if ($tier === null) {
            return;
        }

        if ($tiers === []) {
            throw new InvalidArgumentException("Badge [{$definition->key}] has no tiers — pass a null tier.");
        }

        if (! array_key_exists($tier, $tiers)) {
            $known = implode(', ', array_keys($tiers));

            throw new InvalidArgumentException("Unknown tier [{$tier}] for badge [{$definition->key}]. Known tiers: {$known}.");
        }
    }

    /**
     * The definition's tier map, normalized to key => threshold.
     * Non-int thresholds (impossible from the registry, defensive against
     * hand-edited rows) are dropped so ordering math stays numeric.
     *
     * @return array<string, int>
     */
    private function tierThresholds(BadgeDefinition $definition): array
    {
        $tiers = $definition->tiers;

        if (! is_array($tiers)) {
            return [];
        }

        $normalized = [];

        foreach ($tiers as $tierKey => $threshold) {
            if (is_int($threshold)) {
                $normalized[$tierKey] = $threshold;
            }
        }

        return $normalized;
    }

    /**
     * Ordinal of a tier key within the definition's tier ordering:
     * thresholds ascending (bronze 1 < silver 10 < gold 50). Untiered
     * (null) and stale keys rank below every declared tier.
     *
     * @param  array<string, int>  $tiers
     */
    private function tierRank(?string $tier, array $tiers): int
    {
        if ($tier === null) {
            return -1;
        }

        // Postgres jsonb does not preserve key order (the T2 sync test
        // normalizes with ksort for exactly this reason) — ordering lives
        // in the thresholds, never in key order.
        $ordered = $tiers;
        asort($ordered);

        $rank = array_search($tier, array_keys($ordered), true);

        return $rank === false ? -1 : (int) $rank;
    }

    /**
     * Insert the first badge_user row for a hold (never an upgrade path —
     * the unique constraint makes a second row impossible by design).
     */
    private function createHold(User $user, BadgeDefinition $definition, BadgeAward $award, BadgeSource $source, CarbonInterface $now): BadgeUser
    {
        // Create through the definition relation and associate the user —
        // models, never raw FK ids (Eloquent practices R2).
        $hold = $definition->badgeUsers()->make([
            'tier' => $award->tierKey,
            'source' => $source,
            'first_awarded_at' => $now,
            'last_awarded_at' => $now,
            'evidence_type' => $award->evidenceType,
            'evidence_id' => $award->evidenceId,
        ]);
        $hold->user()->associate($user);
        $hold->save();

        return $hold;
    }

    /**
     * Append one immutable audit row (the table has no updated_at;
     * created_at is set explicitly, cf. ActivityLog).
     */
    private function recordEvent(BadgeUser $held, BadgeEventAction $action, BadgeSource $source, ?User $actor, ?string $reason, ?BadgeAward $award): void
    {
        $held->badgeEvents()->create([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'source' => $source,
            'evidence_type' => $award?->evidenceType,
            'evidence_id' => $award?->evidenceId,
            'created_at' => now(),
        ]);
    }

    private function awardedEvent(string $userId, BadgeAward $award, BadgeSource $source): BadgeAwarded
    {
        return new BadgeAwarded(
            $userId,
            $award->badgeKey,
            $award->tierKey,
            $source,
            $award->evidenceType,
            $award->evidenceId,
        );
    }
}
