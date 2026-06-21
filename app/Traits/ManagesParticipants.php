<?php

namespace App\Traits;

use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Services\BenchService;
use App\Services\ParticipantService;
use App\Services\WaitlistService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;

/**
 * Thin Livewire adapter for participant management.
 *
 * Delegates all domain logic to ParticipantService, WaitlistService,
 * BenchService, and Roster, handling Livewire-specific concerns
 * (authorization, session flash, error bags). The only entity-specific
 * contract it imposes is getEntity(); type-derived metadata (foreign
 * key, participant class, entity name) is resolved via EntityMeta.
 *
 * Requires the consuming Livewire component to implement:
 *   - getEntity(): the Game|Campaign model instance
 *
 * The Blade partial (resources/views/livewire/partials/
 * manage-participants.blade.php) reads getEntityVar/getBackRoute from
 *
 * @include(...) parameters — they are NOT defined here.
 */
trait ManagesParticipants
{
    /** @var string[] */
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
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inviteEmail' => ['nullable', 'email', 'max:255'],
        ];
    }

    // ── Abstract contracts ─────────────────────────────

    /**
     * The Game|Campaign this component manages participants for.
     *
     * Only load-bearing contract: every trait method uses it for
     * authorization and as the service call target. Type-derived
     * metadata is resolved via EntityMeta::fromEntity().
     */
    abstract public function getEntity();

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

        // Cannot invite to a canceled or completed entity
        $entity = $this->getEntity();
        if (in_array($entity->status?->value, ['canceled', 'cancelled', 'completed'])) {
            session()->flash('error', __('common.error_entity_no_longer_available'));

            return;
        }

        $result = $this->participantService()->inviteFriends(
            $this->getEntity(),
            authenticatedUser(),
            array_values($this->selectedFriendIds),
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

        // Cannot invite to a canceled or completed entity
        $entity = $this->getEntity();
        if (in_array($entity->status?->value, ['canceled', 'cancelled', 'completed'])) {
            session()->flash('error', __('common.error_entity_no_longer_available'));

            return;
        }

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
            authenticatedUser(),
            $email,
        );

        if (! $result->success && $result->errorKey) {
            $this->addError('inviteEmail', __($result->errorKey, $result->errorParams));

            return;
        }

        $this->reset('inviteEmail');
        session()->flash('success', __($result->messageKey, $result->messageParams));
    }

    /**
     * Handle the friends-selected event from FriendSearch component.
     *
     * @param  array<int, string>  $ids
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
            authenticatedUser(),
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
            authenticatedUser(),
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
            authenticatedUser(),
        );

        if (! $result->success && $result->errorKey) {
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
            authenticatedUser(),
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
        $authUser = authenticatedUser();

        $result = $this->participantService()->acceptInvitation(
            $participant,
            $entity,
            $authUser,
        );

        if (! $result->success && $result->errorKey) {
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
        $authUser = authenticatedUser();

        $result = $this->participantService()->declineInvitation(
            $participant,
            $authUser,
        );

        if (! $result->success && $result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));

            return;
        }

        session()->flash('success', __($result->messageKey));
    }

    // ── Waitlist / Bench Getters ───────────────────────

    /**
     * Get all waitlisted participants ordered by queue position.
     *
     * @return Collection<int, CampaignParticipant>|Collection<int, GameParticipant>
     */
    public function getWaitlistedParticipants(): Collection
    {
        return $this->participantService()->getWaitlistedParticipants($this->getEntity());
    }

    /**
     * Get all benched participants ordered by bench time.
     *
     * @return Collection<int, CampaignParticipant>|Collection<int, GameParticipant>
     */
    public function getBenchedParticipants(): Collection
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

        $participant = $this->participantService()->findParticipant($this->getEntity(), $participantId);

        try {
            app(WaitlistService::class)->manuallyPromote($participant);
            session()->flash('success', __('common.flash_waitlist_promoted'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_waitlisted'));
        }
    }

    /**
     * Remove a waitlisted participant.
     */
    public function manageRemoveFromWaitlist(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->participantService()->findParticipant($this->getEntity(), $participantId);

        try {
            app(WaitlistService::class)->removeFromWaitlist($participant, authenticatedUser());
            session()->flash('success', __('common.flash_waitlist_removed'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_waitlisted'));
        }
    }

    /**
     * Promote a benched participant to approved status.
     */
    public function managePromoteFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->participantService()->findParticipant($this->getEntity(), $participantId);

        try {
            app(BenchService::class)->promoteFromBench($participant, authenticatedUser());
            session()->flash('success', __('common.flash_bench_promoted'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_benched'));
        }
    }

    /**
     * Remove a benched participant.
     */
    public function manageRemoveFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->participantService()->findParticipant($this->getEntity(), $participantId);

        try {
            app(BenchService::class)->removeFromBench($participant, authenticatedUser());
            session()->flash('success', __('common.flash_bench_removed'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_benched'));
        }
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Find a participant by ID scoped to the entity.
     * Kept for backwards compatibility with overrides (e.g. GameDetail::removeParticipant).
     */
    private function findParticipant(string $participantId): GameParticipant|CampaignParticipant
    {
        return $this->participantService()->findParticipant($this->getEntity(), $participantId);
    }
}
