<?php

namespace App\Traits;

use App\Services\ParticipantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;

/**
 * Thin Livewire adapter for participant management.
 *
 * Delegates all domain logic to ParticipantService and handles
 * Livewire-specific concerns (authorization, session flash, error bags).
 *
 * Requires the consuming Livewire component to implement:
 *   - getEntity():        The Game|Campaign model instance
 *   - getEntityIdColumn(): 'game_id' or 'campaign_id'
 *   - getParticipantModel(): GameParticipant or CampaignParticipant class
 *   - getEntityName():    'Game' or 'Campaign' (for log messages)
 *   - getEntityVar():     'game' or 'campaign' (for Blade variable name)
 *   - getBackRoute():     route name for the back link
 */
trait ManagesParticipants
{
    /** @var int[] User IDs selected from FriendSearch component */
    public array $selectedFriendIds = [];

    /** @var string Email address for email-based invitations */
    public string $inviteEmail = '';

    /**
     * Tracks which inline confirmation is currently active.
     * Format: "{action}-{id}" e.g. "remove-participant-123".
     * Only one confirmation can be active at a time.
     */
    public ?string $confirmingAction = null;

    /**
     * Livewire validation rules (v4 pattern — no #[Validate] attributes).
     */
    public function rules(): array
    {
        return [
            'inviteEmail' => ['nullable', 'email', 'max:255'],
        ];
    }

    // ── Abstract contracts ─────────────────────────────

    abstract public function getEntity();

    abstract public function getEntityIdColumn(): string;

    abstract public function getParticipantModel(): string;

    abstract public function getEntityName(): string;

    abstract public function getEntityVar(): string;

    abstract public function getBackRoute(): string;

    // ── Service accessor ───────────────────────────────

    protected function participantService(): ParticipantService
    {
        return app(ParticipantService::class);
    }

    // ── Invite ─────────────────────────────────────────

    /**
     * Invites all selected friends as participants.
     */
    public function inviteParticipants(): void
    {
        $this->authorize('update', $this->getEntity());

        if (empty($this->selectedFriendIds)) {
            $this->addError('selectedFriendIds', __('people.error_select_at_least_one_friend'));

            return;
        }

        $result = $this->participantService()->inviteFriends(
            $this->getEntity(),
            Auth::user(),
            $this->selectedFriendIds,
        );

        $this->reset('selectedFriendIds');

        if ($result->invitedCount > 0) {
            session()->flash('success', trans_choice('people.flash_friends_invited', $result->invitedCount, ['count' => $result->invitedCount]));
        } elseif ($result->skippedCount > 0) {
            session()->flash('error', __('people.error_no_valid_friends_to_invite'));
        }
    }

    /**
     * Invite someone by email address.
     */
    public function inviteByEmail(): void
    {
        $this->authorize('update', $this->getEntity());

        $email = trim($this->inviteEmail);

        // Validate email format
        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email', 'max:255']]
        );

        if ($validator->fails()) {
            $this->addError('inviteEmail', __('people.error_invalid_email'));

            return;
        }

        $result = $this->participantService()->inviteByEmail(
            $this->getEntity(),
            Auth::user(),
            $email,
        );

        if (!$result->success && $result->errorKey) {
            $this->addError('inviteEmail', __($result->errorKey, $result->errorParams));

            return;
        }

        $this->reset('inviteEmail');
        session()->flash('success', __($result->messageKey, $result->messageParams));
    }

    /**
     * Handle the friends-selected event from FriendSearch component.
     */
    #[On('friends-selected')]
    public function onFriendsSelected(array $ids): void
    {
        $this->selectedFriendIds = $ids;
    }

    // ── Approve / Reject Application ───────────────────

    public function approveApplication(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->approveApplication(
            $participant,
            $entity,
            Auth::user(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }

    public function rejectApplication(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->rejectApplication(
            $participant,
            $entity,
            Auth::user(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }

    // ── Remove Participant ─────────────────────────────

    public function removeParticipant(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->removeParticipant(
            $participant,
            $entity,
            Auth::user(),
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    // ── Cancel Invite ──────────────────────────────────

    public function cancelInvite(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findPendingInvite($entity, $participantId);

        $result = $this->participantService()->cancelInvite(
            $participant,
            $entity,
            Auth::user(),
        );

        session()->flash('success', __($result->messageKey));
    }

    // ── Accept / Decline Invitation ────────────────────

    /**
     * Accept a pending invitation for the authenticated user.
     */
    public function acceptInvitation(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);
        $authUser = Auth::user();

        $result = $this->participantService()->acceptInvitation(
            $participant,
            $entity,
            $authUser,
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));

            return;
        }

        session()->flash('success', __($result->messageKey, $result->messageParams));
    }

    /**
     * Decline a pending invitation for the authenticated user.
     */
    public function declineInvitation(string $participantId): void
    {
        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);
        $authUser = Auth::user();

        $result = $this->participantService()->declineInvitation(
            $participant,
            $entity,
            $authUser,
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    // ── Waitlist / Bench Getters ───────────────────────

    /**
     * Get all waitlisted participants ordered by queue position.
     */
    public function getWaitlistedParticipants()
    {
        return $this->participantService()->getWaitlistedParticipants($this->getEntity());
    }

    /**
     * Get all benched participants ordered by bench time.
     */
    public function getBenchedParticipants()
    {
        return $this->participantService()->getBenchedParticipants($this->getEntity());
    }

    // ── Waitlist / Bench Actions ───────────────────────

    /**
     * Promote a waitlisted participant to approved status.
     */
    public function managePromoteFromWaitlist(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->promoteFromWaitlist(
            $participant,
            $entity,
            Auth::user(),
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    /**
     * Remove a waitlisted participant.
     */
    public function manageRemoveFromWaitlist(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->removeFromWaitlist(
            $participant,
            $entity,
            Auth::user(),
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    /**
     * Promote a benched participant to approved status.
     */
    public function managePromoteFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->promoteFromBench(
            $participant,
            $entity,
            Auth::user(),
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    /**
     * Remove a benched participant.
     */
    public function manageRemoveFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $entity = $this->getEntity();
        $participant = $this->participantService()->findParticipant($entity, $participantId);

        $result = $this->participantService()->removeFromBench(
            $participant,
            $entity,
            Auth::user(),
        );

        if (!$result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Find a participant by ID scoped to the entity.
     * Kept for backwards compatibility with overrides (e.g. GameDetail::removeParticipant).
     */
    private function findParticipant(string $participantId)
    {
        return $this->participantService()->findParticipant($this->getEntity(), $participantId);
    }
}
