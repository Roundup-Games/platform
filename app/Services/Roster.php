<?php

namespace App\Services;

use App\Dto\EntityMeta;
use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\Game;
use App\Notifications\BelowMinPlayersWarning;
use Illuminate\Support\Facades\Log;

/**
 * Owns the participation-lifecycle cascades that fire when an entity's
 * roster changes en masse.
 *
 * Before this module existed, two cascades had no orchestrator:
 *
 *  Cancellation — rejecting every waitlisted and benched participant when a
 *  game or campaign is cancelled — was split across WaitlistService and
 *  BenchService with no caller. A docblock in WaitlistService admitted the
 *  caller had to remember to invoke BenchService::handleEntityCancellation
 *  separately. In practice nothing wired either call into the production
 *  cancel flow, so the cascade was tested but never ran.
 *
 *  Departure — promoting the next waitlisted player into an opened slot and
 *  warning the host about a below-minimum roster — was invoked from seven
 *  Livewire call sites with inconsistent preconditions: two called
 *  promoteAllOnCancel without the isBenchMode guard the other five had, and
 *  only three of seven called the below-min notification. The host of a
 *  game where a player left via the wrong entry point silently received no
 *  warning.
 *
 * Roster::onCancellation and Roster::onDeparture are the seams. The cancel
 * and leave/remove flows call them; promotion, rejection, notification, and
 * their ordering live behind the interface.
 */
class Roster
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly BenchService $bench,
        private readonly ParticipantService $participants,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Reject every waitlisted and benched participant on a cancelled entity.
     *
     * Idempotent: re-running finds no matching participants. Safe to call
     * from cancel flows that may be retried.
     */
    public function onCancellation(Game|Campaign $entity): void
    {
        try {
            $this->waitlist->handleEntityCancellation($entity);
            $this->bench->handleEntityCancellation($entity);
        } catch (\Throwable $e) {
            // The cancellation itself has already persisted; a cascade failure
            // must not break the cancel flow. Log and continue — the affected
            // participants can be reconciled by a repair job.
            $type = $entity instanceof Campaign ? 'campaign' : 'game';
            Log::error('roster.cancellation_cascade_failed', [
                'entity_type' => $type,
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Promote the next waitlisted player into an opened slot and warn the
     * host if the roster drops below min_players.
     *
     * Called after a participant departs (self-leave, host-removes, or
     * waitlist-leave). The departure (status → Rejected/Removed) must be
     * persisted before this call. Promotion is a no-op in bench mode (no
     * waitlisted participants exist) so the isBenchMode guard that was
     * inconsistently applied at five of seven call sites is unnecessary
     * here — calling unconditionally is safe and simpler.
     */
    public function onDeparture(Game|Campaign $entity): void
    {
        try {
            $this->waitlist->promoteAllOnCancel($entity);
            $this->notifyIfBelowMinPlayers($entity);
        } catch (\Throwable $e) {
            $type = $entity instanceof Campaign ? 'campaign' : 'game';
            Log::error('roster.departure_cascade_failed', [
                'entity_type' => $type,
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a BelowMinPlayersWarning notification to the game host if the
     * approved roster has dropped below min_players.
     *
     * No-op for campaigns (no warning notification) and for entities with
     * no min_players. promoteAllOnCancel already logs the condition; this
     * method adds the host notification that was previously only wired at
     * three of seven departure sites.
     */
    private function notifyIfBelowMinPlayers(Game|Campaign $entity): void
    {
        if (! $entity->min_players) {
            return;
        }

        $approvedCount = $this->participants->getApprovedParticipantCount($entity);

        if ($approvedCount >= $entity->min_players) {
            return;
        }

        $meta = EntityMeta::fromEntity($entity);

        Log::warning("{$meta->type}.below_min_players", [
            $meta->foreignKey => $entity->id,
            'current_roster' => $approvedCount,
            'min_players' => $entity->min_players,
        ]);

        if (! $entity instanceof Game) {
            return;
        }

        $owner = $entity->owner;
        if ($owner === null) {
            return;
        }

        try {
            $this->notifications->send(
                $owner,
                new BelowMinPlayersWarning($entity, $approvedCount, $entity->min_players),
                NotificationCategory::BelowMinPlayers,
            );
        } catch (\Throwable $e) {
            Log::error('notification.below_min_players_dispatch_failed', [
                $meta->foreignKey => $entity->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
