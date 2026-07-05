<?php

namespace App\Services;

use App\Dto\CapacityChangeResult;
use App\Dto\DemotionPreview;
use App\Dto\DemotionResult;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Exceptions\DemotionRequiresConfirmation;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\SeatDemoted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for host-initiated max_players changes on a Game.
 *
 * Capacity adjustment has three distinct shapes:
 *  - **increase**: raise max_players, then auto-fill the newly-opened slots
 *    from the waitlist (promotees land in Pending — existing confirmation
 *    semantics, MEM661).
 *  - **silent decrease**: lower max_players while still at/above the approved
 *    count — no roster change, purely a limit tightening.
 *  - **decrease below approved count**: requires explicit confirmation because
 *    one or more approved players must be demoted. {@see decrease()} refuses
 *    with a {@see DemotionRequiresConfirmation} exception; the LIFO demotion
 *    itself is handled by {@see previewDemotion()} (pure read for the confirm
 *    UI) and {@see demote()} (the write, invoked on confirm).
 *
 * Every change happens inside a `DB::transaction` with a `lockForUpdate()` on
 * the Game row, serialising concurrent capacity edits against every other
 * waitlist/participant write path that also locks the entity row (the same
 * convention as {@see WaitlistService::promoteNext()}).
 */
class CapacityService
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Raise max_players and auto-promote waitlisted players to fill the new slots.
     *
     * Promotees land in Pending (pending confirmation) via the existing
     * type-agnostic {@see WaitlistService::promoteAllOnCancel()} machinery —
     * identical to the post-cancellation fill path.
     *
     * @param  int  $newMax  The new max_players; must be >= 1 (0 means
     *                       "unlimited" elsewhere in the system, which is never
     *                       a valid result of an increase).
     *
     * @throws \InvalidArgumentException if $newMax is less than 1.
     */
    public function increase(Game $game, int $newMax): CapacityChangeResult
    {
        // Service-level guard against the 0=unlimited footgun. Even if the
        // Livewire layer (T04) also validates, the service is the canonical
        // boundary for this invariant.
        if ($newMax < 1) {
            throw new \InvalidArgumentException('max_players must be at least 1.');
        }

        return DB::transaction(function () use ($game, $newMax) {
            // Re-fetch the locked row to serialise against concurrent capacity
            // edits and other entity-row lockers.
            /** @var Game $locked */
            $locked = Game::where('id', $game->id)->lockForUpdate()->firstOrFail();

            $oldMax = $locked->max_players;

            // Promotees land in Pending, so count Pending rows before/after to
            // measure how many waitlisted players were auto-promoted.
            $pendingBefore = $this->pendingCount($locked);

            $locked->update(['max_players' => $newMax]);

            // capacity-increase: fill the newly-opened slots from the waitlist.
            // promoteAllOnCancel() opens (newMax - approvedCount) slots and
            // promotes that many waitlisted players (or until the queue empties).
            $this->waitlist->promoteAllOnCancel($locked);

            $promotedCount = max(0, $this->pendingCount($locked) - $pendingBefore);

            Log::info('capacity.increase', [
                'entity_id' => $locked->id,
                'old_max' => $oldMax,
                'new_max' => $newMax,
                'promoted_count' => $promotedCount,
            ]);

            return new CapacityChangeResult($oldMax, $newMax, $promotedCount);
        });
    }

    /**
     * Lower max_players.
     *
     * This is the **silent** branch only: when $newMax remains at or above the
     * current approved-participant count, the roster is unaffected and the
     * change is a pure limit tightening. When $newMax would fall below the
     * approved count, this throws {@see DemotionRequiresConfirmation} so the
     * Livewire layer (T04) can surface a confirm modal; the actual LIFO
     * demotion is implemented in {@see demote()}.
     *
     * This method is deliberately NOT modified by T03's demotion work — T02's
     * contract (silent above approved, throw below approved) and T02's test
     * case (f) stay valid. The Livewire layer routes: increase() up,
     * decrease() for silent-down, previewDemotion()+demote() for below-approved.
     *
     * @throws DemotionRequiresConfirmation if $newMax < approved participant count.
     */
    public function decrease(Game $game, int $newMax): CapacityChangeResult
    {
        return DB::transaction(function () use ($game, $newMax) {
            /** @var Game $locked */
            $locked = Game::where('id', $game->id)->lockForUpdate()->firstOrFail();

            $oldMax = $locked->max_players;
            $approvedCount = $locked->approvedParticipantCount();

            // Silent decrease: the limit stays at/above the seated roster, so
            // no one is displaced.
            if ($newMax >= $approvedCount) {
                $locked->update(['max_players' => $newMax]);

                Log::info('capacity.decrease', [
                    'entity_id' => $locked->id,
                    'old_max' => $oldMax,
                    'new_max' => $newMax,
                    'displaced_count' => 0,
                ]);

                return new CapacityChangeResult($oldMax, $newMax, 0);
            }

            // Below the approved count — demotion requires explicit confirmation.
            // The Livewire layer catches this to show the confirm modal, then
            // calls previewDemotion() to render it and demote() on confirm.
            throw new DemotionRequiresConfirmation($approvedCount, $newMax);
        });
    }

    /**
     * Pure read for the capacity-decrease confirm UI: who would be displaced,
     * who is exempt, and the effective count under the CAP rule.
     *
     * Performs NO writes and takes no lock — it snapshots the current roster
     * so the host can see the consequence before confirming. {@see demote()}
     * re-selects the set under a lock at confirm time (the roster may shift
     * between preview and confirm if a player leaves/joins concurrently).
     *
     * The CAP rule: if the host requests more demotions than demotable
     * players exist, only the demotable set is actually demoted. The owner
     * and manually-promoted players are intentionally allowed to sit
     * over-capacity per manuallyPromote's "capacity is not enforced" contract
     * — {@see DemotionPreview} surfaces this so the host is not surprised.
     */
    public function previewDemotion(Game $game, int $newMax): DemotionPreview
    {
        $approvedCount = $game->approvedParticipantCount();
        $requested = max(0, $approvedCount - $newMax);

        $demotable = $this->demotableParticipants($game);
        $exempt = $this->exemptParticipants($game);

        $actual = min($requested, $demotable->count());

        $wouldDemote = $demotable->take($actual)->map(fn (GameParticipant $p) => [
            'id' => $p->id,
            'name' => $p->user->name ?? '',
            'approved_at' => $p->approved_at?->toIso8601String(),
        ])->values()->all();

        $exemptRows = $exempt->map(fn (GameParticipant $p) => [
            'id' => $p->id,
            'name' => $p->user->name ?? '',
            'reason' => $p->role === ParticipantRole::Owner ? 'owner' : 'manually_promoted',
        ])->values()->all();

        return new DemotionPreview(
            requestedDisplaced: $requested,
            demotableCount: $demotable->count(),
            actualDemotionCount: $actual,
            wouldDemote: $wouldDemote,
            exempt: $exemptRows,
        );
    }

    /**
     * Demote the most-recently-approved non-exempt players to Waitlisted and
     * lower max_players, after the host has confirmed via the preview modal.
     *
     * Selection — approved-time LIFO with two exemptions:
     *  - non-exempt Approved players (role != Owner AND promoted_manually = false)
     *    ordered approved_at DESC (most-recently-approved demoted first);
     *  - exempt: the owner (role = Owner) and manually-promoted players
     *    (promoted_manually = true) are never demoted — they may sit over the
     *    new cap per manuallyPromote's contract.
     *
     * Each demoted player is moved to Waitlisted with their ORIGINAL
     * waitlisted_at preserved (front-of-queue, fair priority — they had a seat
     * and lost it involuntarily); when waitlisted_at was null (e.g. a directly
     * invited player who was never waitlisted) it is set to now() for
     * deterministic back-of-queue ordering (decision D108). attendance_status
     * is cleared defensively.
     *
     * Guards: throws {@see \DomainException} if the game is already Completed
     * or has attendance_resolved_at set — demoting post-resolution is
     * nonsensical (the player already has a stamped status) and would break
     * the verified-zero-reliability-penalty guarantee.
     *
     * Notifications are dispatched OUTSIDE the transaction (after commit),
     * each wrapped in try/catch+Log so a notification failure can never roll
     * back the demotion (mirrors {@see WaitlistService::notifyPromotion}).
     *
     * @param  string  $reason  Host-supplied reason shown in the SeatDemoted notification.
     * @param  User  $actor  The host performing the demotion (audit log only; block-list checks use the game owner via {@see SeatDemoted::getActor()}).
     *
     * @throws \DomainException if the game is Completed or attendance is already resolved.
     */
    public function demote(Game $game, int $newMax, string $reason, User $actor): DemotionResult
    {
        // The displaced set + exempt count are produced inside the transaction
        // and returned so notifications can fan out AFTER commit.
        [$displaced, $exemptCount] = DB::transaction(function () use ($game, $newMax, $actor) {
            /** @var Game $locked */
            $locked = Game::where('id', $game->id)->lockForUpdate()->firstOrFail();

            // GUARDS — demoting post-completion/post-resolution is nonsensical
            // and would undermine the zero-reliability-penalty guarantee.
            if ($locked->status === GameStatus::Completed) {
                Log::info('capacity.demotion_refused', [
                    'entity_id' => $locked->id,
                    'reason' => 'completed',
                ]);

                throw new \DomainException('Cannot adjust capacity on a completed game.');
            }

            if ($locked->attendance_resolved_at !== null) {
                Log::info('capacity.demotion_refused', [
                    'entity_id' => $locked->id,
                    'reason' => 'resolved',
                ]);

                throw new \DomainException('Cannot adjust capacity after attendance has been resolved.');
            }

            $oldMax = $locked->max_players;
            $approvedCount = $locked->approvedParticipantCount();
            $requested = max(0, $approvedCount - $newMax);

            // Re-select the demote set exactly as previewDemotion (LIFO
            // approved_at DESC, exemptions), under the lock.
            $demotable = $this->demotableParticipants($locked);
            $exempt = $this->exemptParticipants($locked);
            $actual = min($requested, $demotable->count());

            /** @var Collection<int, GameParticipant> $toDemote */
            $toDemote = $demotable->take($actual)->values();

            foreach ($toDemote as $p) {
                // Preserve original waitlisted_at (front-of-queue, fair priority);
                // when null (never-waitlisted) set to now() for deterministic
                // back-of-queue ordering (decision D108). Clear attendance_status
                // defensively — it should not exist pre-game, but be safe.
                $p->update([
                    'status' => ParticipantStatus::Waitlisted->value,
                    'attendance_status' => null,
                    'waitlisted_at' => $p->waitlisted_at ?? now(),
                ]);
            }

            $locked->update(['max_players' => $newMax]);

            Log::info('capacity.decrease', [
                'entity_id' => $locked->id,
                'old_max' => $oldMax,
                'new_max' => $newMax,
                'displaced_count' => $toDemote->count(),
                'exempt_count' => $exempt->count(),
                'actor_id' => $actor->id,
            ]);

            return [$toDemote, $exempt->count()];
        });

        // Dispatch SeatDemoted notifications OUTSIDE the transaction (after
        // commit) so a dispatch failure cannot roll back the demotion. Each is
        // wrapped in try/catch+Log (capacity.notification_failed) — mirrors
        // WaitlistService::notifyPromotion. NotificationService::send also
        // catches internally, but this provides a capacity-specific audit
        // channel and protects against future changes to that service.
        foreach ($displaced as $p) {
            $user = $p->user;

            if ($user === null) {
                continue;
            }

            try {
                $this->notifications->send(
                    $user,
                    new SeatDemoted($game, $reason),
                    NotificationCategory::SeatDemoted,
                );
            } catch (\Throwable $e) {
                Log::warning('capacity.notification_failed', [
                    'participant_id' => $p->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new DemotionResult(
            demotedCount: $displaced->count(),
            demoted: $displaced->map(fn (GameParticipant $p) => (string) $p->id)->all(),
            exemptCount: $exemptCount,
        );
    }

    /**
     * Select the demotable set: non-exempt Approved players ordered
     * approved_at DESC (most-recently-approved first). Null approved_at sorts
     * last (defensive — T01 backfills all approved rows, so this is a guard
     * against future regressions, demoting the unknown-time rows least readily).
     *
     * @return Collection<int, GameParticipant>
     */
    private function demotableParticipants(Game $game): Collection
    {
        return $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('role', '!=', ParticipantRole::Owner->value)
            ->where('promoted_manually', false)
            ->orderByRaw('approved_at IS NULL ASC, approved_at DESC')
            ->with('user')
            ->get();
    }

    /**
     * Select the exempt set: Approved players that are never demoted — the
     * owner (role = Owner) and manually-promoted players (promoted_manually = true).
     *
     * @return Collection<int, GameParticipant>
     */
    private function exemptParticipants(Game $game): Collection
    {
        return $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where(function ($query) {
                $query->where('role', ParticipantRole::Owner->value)
                    ->orWhere('promoted_manually', true);
            })
            ->with('user')
            ->get();
    }

    /**
     * Count Pending participants for an entity (fresh query inside the lock).
     */
    private function pendingCount(Game $game): int
    {
        return $game->participants()
            ->where('status', ParticipantStatus::Pending->value)
            ->count();
    }
}
