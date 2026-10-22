<?php

namespace App\Services\Badges;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Founding Table (spec §3 Support & Tenure row, D187/D208): joined
 * during the launch window — every account created while the window
 * is open. The window is open while badges.founding_table.closes_at
 * is null (the platform is pre-launch) and stays open until now
 * passes the configured timestamp; after that the evaluator stops
 * proposing and no new account can ever earn it.
 *
 * - While the window is open, every existing account trivially
 *   joined during it (created_at <= now <= closes_at), so the
 *   proposal is the user themselves — evidence morph alias 'user'
 *   (D170) points the award at the founding account. That also keeps
 *   evaluate() a config read, exactly as cheap as the pipeline
 *   (every registered evaluator runs on MembershipActivated) needs.
 * - sweep() is the stateless backfill over the whole user table —
 *   the cohort is everyone while the window is open and nobody once
 *   it has closed, so no grouped cohort predicate is needed.
 * - Tierless OneTime by design: the proposal carries a null tier, so
 *   no tierThresholds() here.
 * - No lapse (spec §4): closing the window never strips a founding
 *   member — existing holds keep their rows forever; admin
 *   revocation is the removal path.
 */
class FoundingTableEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'founding_table';

    public function evaluate(User $user): ?BadgeAward
    {
        $evidence = $this->proposalFor($user);

        if ($evidence === null) {
            return null;
        }

        return BadgeAward::for(self::BADGE_KEY, null, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        // Existing holders are excluded from the cohort: while the
        // window is open this sweep runs nightly, and iterating every
        // account just to conclude AlreadyHeld is O(users) round trips
        // forever (award() re-locks and re-reads each hold). The
        // exclusion is outcome-equivalent — founding holds never lapse
        // (spec §4), tierless so nothing upgrades, and a revoked hold
        // re-proposing would only hit RefusedRevoked anyway. Cost drops
        // to O(new accounts).
        User::query()
            ->whereDoesntHave('badges', fn ($query) => $query
                ->whereHas('badgeDefinition', fn ($definition) => $definition
                    ->where('key', self::BADGE_KEY)))
            ->chunkById(200, function ($users) use ($service): void {
                /** @var iterable<User> $users */
                foreach ($users as $user) {
                    try {
                        $evidence = $this->proposalFor($user);

                        if ($evidence === null) {
                            // Window closed (possibly mid-sweep): nothing to
                            // propose and, per the no-lapse rule, nothing to
                            // strip from existing holds.
                            continue;
                        }

                        $service->award($user, self::BADGE_KEY, null, $evidence);
                    } catch (Throwable $e) {
                        // Per-user isolation — see CohortSweepEvaluator::sweep().
                        Log::error('badges.sweep.user_failed', [
                            'badge' => self::BADGE_KEY,
                            'user_id' => $user->id,
                            'exception' => $e::class.': '.$e->getMessage(),
                        ]);
                    }
                }
            });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The founding account itself — the evidence pointer for every
     * award of this badge — while the launch window is open, null
     * once it has closed (D208).
     */
    private function proposalFor(User $user): ?User
    {
        if ($this->windowClosed()) {
            return null;
        }

        return $user;
    }

    private function windowClosed(): bool
    {
        $closesAt = $this->windowClosesAt();

        return $closesAt !== null && now()->gt($closesAt);
    }

    /**
     * The configured close timestamp, null while the window is open.
     * Null-safe against both config shapes: the shipped default (null)
     * and any timestamp string an operator sets (or a test overrides).
     */
    private function windowClosesAt(): ?CarbonInterface
    {
        $closesAt = config('badges.founding_table.closes_at');

        if ($closesAt instanceof CarbonInterface) {
            return $closesAt;
        }

        return is_string($closesAt) && $closesAt !== '' ? Carbon::parse($closesAt) : null;
    }
}
