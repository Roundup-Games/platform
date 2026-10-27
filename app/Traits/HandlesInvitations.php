<?php

namespace App\Traits;

use App\Dto\ParticipantResult;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Services\ParticipantLifecycle;

/**
 * Invitation accept/decline actions for multi-entity hub pages.
 *
 * The participant-scoped counterpart to ManagesParticipants: detail pages
 * bind a single entity via getEntity() (D057 kept that trait off hub
 * pages), while a hub page renders invitations across many entities of
 * one kind. This concern resolves the participant row by ID through the
 * consuming page's participant model (a class-string, not an entity
 * binding), delegates the transition to ParticipantLifecycle — the single
 * owner of lifecycle state changes — and maps the ParticipantResult onto
 * the standard success/error session flash, the exact block the
 * GamesPage and CampaignsPage twins previously duplicated.
 *
 * Requires the consuming Livewire component to implement:
 *   - invitationParticipantClass(): the participant model class to
 *     resolve invitation rows from
 */
trait HandlesInvitations
{
    // ── Abstract contracts ─────────────────────────────

    /**
     * The participant model class this page resolves invitation rows from.
     *
     * @return class-string<GameParticipant|CampaignParticipant>
     */
    abstract protected function invitationParticipantClass(): string;

    // ── Accept / Decline Invitation ────────────────────

    /**
     * Accept a pending invitation for the authenticated user.
     */
    public function acceptInvitation(string $participantId): void
    {
        $participant = $this->invitationParticipantClass()::findOrFail($participantId);
        $entity = $participant->getEntity();

        if ($entity === null) {
            return;
        }

        $result = app(ParticipantLifecycle::class)->acceptInvitation(
            $participant,
            $entity,
            authenticatedUser(),
        );

        $this->flashParticipantResult($result);
    }

    /**
     * Decline a pending invitation for the authenticated user.
     */
    public function declineInvitation(string $participantId): void
    {
        $participant = $this->invitationParticipantClass()::findOrFail($participantId);
        $entity = $participant->getEntity();

        if ($entity === null) {
            return;
        }

        $result = app(ParticipantLifecycle::class)->declineInvitation(
            $participant,
            authenticatedUser(),
        );

        $this->flashParticipantResult($result);
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Map a lifecycle ParticipantResult onto the standard session flash.
     */
    protected function flashParticipantResult(ParticipantResult $result): void
    {
        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }
}
