<?php

namespace App\Traits;

use App\Dto\EntityMeta;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesWaitlist
{
    /**
     * Join the waitlist for a full standalone game.
     */
    public function joinWaitlist(): void
    {
        $viewer = authenticatedUser();

        try {
            app(WaitlistService::class)->addToWaitlist($this->getEntity(), $viewer);
            session()->flash('success', __('common.content_added_to_waitlist'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Confirm a waitlist promotion spot.
     */
    public function confirmWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        try {
            app(WaitlistService::class)->confirmPromotion($participant);
            session()->flash('success', __('games.content_waitlist_spot_confirmed'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Decline a waitlist promotion spot.
     */
    public function declineWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->declinePromotion($participant);
        session()->flash('success', __('games.content_waitlist_spot_declined'));
    }

    /**
     * Host manually promotes a waitlisted player (skips FIFO).
     */
    public function manualPromote(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipantOrFail($participantId);

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->manuallyPromote($participant);
        session()->flash('success', __('games.flash_manual_promote_success'));
    }

    /**
     * Cancel a participant's own attendance with late-cancellation detection.
     */
    public function cancelOwnParticipation(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();
        $entity = $this->getEntity();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // Guard against re-stamping a terminal-state participant (Rejected /
        // Removed). depart() would overwrite removed_at / removed_by on each
        // call, corrupting the audit trail.
        if ($participant->status !== ParticipantStatus::Approved) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(ParticipantLifecycle::class)->depart($participant, $viewer);

        session()->flash('success', __('common.flash_participant_removed'));
    }

    /**
     * Leave the waitlist as a waitlisted participant.
     *
     * Routes through ParticipantLifecycle::depart() inside the row lock so
     * the departure records its audit stamps (removed_by / removed_at) and
     * the departure cascade runs exactly once — promotion of the next
     * waitlisted player when an approved slot is open (typically a no-op
     * while still at capacity) plus the below-min warning. depart() owns
     * Roster::onDeparture; this call site must never hand-sequence it.
     */
    public function leaveWaitlist(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $entity = $this->getEntity();
        $meta = EntityMeta::fromEntity($entity);

        $didLeave = false;
        DB::transaction(function () use ($participant, $viewer, $entity, $meta, &$didLeave) {
            // Lock ordering: entity row FIRST, then the participant row —
            // the canonical order every entity-row locker uses (promoteNext,
            // acceptInvitation, ProcessDiscordLeave). Locking the participant
            // first inverted the order against depart()'s cascade: the
            // cascade's promoteNext() takes the entity lock and then the
            // first waitlisted participant row — which can be this very
            // leaver's row — so a concurrent promotion holding the entity
            // lock formed an AB-BA deadlock (PG kills one of the two
            // transactions; the victim's whole leave rolled back).
            $meta->entityClass::lockForUpdate()->findOrFail($entity->getKey());

            // Lock the participant row to prevent concurrent status changes
            // (e.g., user confirming via web while this leave action runs)
            $locked = $participant->newQuery()
                ->where('id', $participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ParticipantStatus::Waitlisted) {
                return;
            }

            // Single lifecycle call: departure write (status → Rejected with
            // removed_by/removed_at audit stamps; no attendance scoring — a
            // Waitlisted row was never Approved) plus the departure cascade.
            app(ParticipantLifecycle::class)->depart($locked, $viewer);
            $didLeave = true;
        });

        if ($didLeave) {
            Log::info('waitlist.participant_left', [
                'entity_type' => $meta->type,
                'entity_id' => $entity->id,
                'user_id' => $viewer->id,
                'participant_id' => $participant->id,
            ]);

            session()->flash('success', __('games.flash_left_waitlist'));
        }
    }

    // ── Private Helpers ────────────────────────────────

    /**
     * Find a participant by ID scoped to the current entity, or throw 404.
     */
    protected function findParticipantOrFail(string $participantId): GameParticipant|CampaignParticipant
    {
        $entity = $this->getEntity();
        $meta = EntityMeta::fromEntity($entity);

        return $meta->participantClass::where('id', $participantId)
            ->where($meta->foreignKey, $entity->id)
            ->firstOrFail();
    }
}
