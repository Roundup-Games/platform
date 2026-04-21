<?php

namespace App\Traits;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameInvitation;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Shared participant management logic for Games and Campaigns.
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

    // ── Abstract contracts ─────────────────────────────

    abstract public function getEntity();

    abstract public function getEntityIdColumn(): string;

    abstract public function getParticipantModel(): string;

    abstract public function getEntityName(): string;

    abstract public function getEntityVar(): string;

    abstract public function getBackRoute(): string;

    // ── Invite ─────────────────────────────────────────

    /**
     * Invites all selected friends as participants.
     * Called when the user clicks "Send Invites" after selecting friends via FriendSearch.
     * Each selected user is validated to be a mutual friend (per D048).
     */
    public function inviteParticipants(): void
    {
        if (empty($this->selectedFriendIds)) {
            $this->addError('selectedFriendIds', __('people.error_select_at_least_one_friend'));

            return;
        }

        $authUser = Auth::user();
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();
        $participantModel = $this->getParticipantModel();
        $invitedCount = 0;
        $skippedCount = 0;

        foreach ($this->selectedFriendIds as $userId) {
            $userId = (int) $userId;
            $targetUser = User::find($userId);

            // Skip if user doesn't exist (stale selection)
            if (! $targetUser) {
                $skippedCount++;
                Log::warning($this->getEntityName() . ' invite skipped: user not found', [
                    $entityIdColumn => $entity->id,
                    'target_user_id' => $userId,
                ]);
                continue;
            }

            // Skip self-invite
            if ($targetUser->id === $authUser->id) {
                $skippedCount++;
                continue;
            }

            // Validate mutual friendship (D048: friends only)
            if (! $authUser->isFriend($targetUser)) {
                $skippedCount++;
                Log::warning($this->getEntityName() . ' invite skipped: not a friend', [
                    $entityIdColumn => $entity->id,
                    'target_user_id' => $targetUser->id,
                    'invited_by' => $authUser->id,
                ]);
                continue;
            }

            // Skip if already a participant
            if ($entity->participants()->where('user_id', $targetUser->id)->exists()) {
                $skippedCount++;
                continue;
            }

            $participantModel::create([
                $entityIdColumn => $entity->id,
                'user_id' => $targetUser->id,
                'role' => 'invited',
                'status' => 'pending',
            ]);

            Log::info($this->getEntityName() . ' participant invited', [
                $entityIdColumn => $entity->id,
                'invited_user_id' => $targetUser->id,
                'invited_by' => $authUser->id,
            ]);

            // Dispatch invitation notification
            try {
                $notificationClass = $this->getEntityName() === 'Game'
                    ? new GameInvitation($entity, $authUser)
                    : new CampaignInvitation($entity, $authUser);
                $category = $this->getEntityName() === 'Game'
                    ? NotificationCategory::GameInvitation
                    : NotificationCategory::CampaignInvitation;

                app(NotificationService::class)->send($targetUser, $notificationClass, $category);
            } catch (\Throwable $e) {
                Log::error('notification.invite_dispatch_failed', [
                    'entity_type' => $this->getEntityName(),
                    $entityIdColumn => $entity->id,
                    'target_user_id' => $targetUser->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $invitedCount++;
        }

        $this->reset('selectedFriendIds');

        if ($invitedCount > 0) {
            session()->flash('success', trans_choice('people.flash_friends_invited', $invitedCount, ['count' => $invitedCount]));
        } elseif ($skippedCount > 0) {
            session()->flash('error', __('people.error_no_valid_friends_to_invite'));
        }
    }

    /**
     * Handle the friends-selected event from FriendSearch component.
     * Syncs the selected friend IDs with the trait's property.
     */
    #[On('friends-selected')]
    public function onFriendsSelected(array $ids): void
    {
        $this->selectedFriendIds = $ids;
    }

    // ── Approve Application ────────────────────────────

    public function approveApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update([
            'role' => 'player',
            'status' => 'approved',
        ]);

        $entity->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'approved']);

        Log::info($this->getEntityName() . ' application approved', [
            $entityIdColumn => $entity->id,
            'user_id' => $participant->user_id,
            'approved_by' => Auth::id(),
        ]);

        // Notify applicant that their application was approved
        try {
            $applicant = User::find($participant->user_id);
            if ($applicant) {
                $entityType = strtolower($this->getEntityName());
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationApproved($entity, $entityType, Auth::user()),
                    NotificationCategory::ApplicationApproved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_approved_dispatch_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'applicant_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('common.flash_application_approved'));
    }

    public function rejectApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update(['status' => 'rejected']);

        $entity->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' application rejected', [
            $entityIdColumn => $entity->id,
            'user_id' => $participant->user_id,
            'rejected_by' => Auth::id(),
        ]);

        // Notify applicant that their application was rejected
        try {
            $applicant = User::find($participant->user_id);
            if ($applicant) {
                $entityType = strtolower($this->getEntityName());
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationRejected($entity, $entityType, Auth::user()),
                    NotificationCategory::ApplicationRejected
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_rejected_dispatch_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'applicant_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('common.flash_application_rejected'));
    }

    // ── Remove Participant ─────────────────────────────

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();

        if ($participant->role === 'owner') {
            session()->flash('error', __('common.error_cannot_remove_the_entity_owner', ['entity' => strtolower($this->getEntityName())]));

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' participant removed', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_participant_removed'));
    }

    // ── Cancel Invite ──────────────────────────────────

    public function cancelInvite(string $participantId): void
    {
        $participantModel = $this->getParticipantModel();
        $entity = $this->getEntity();

        $participant = $participantModel::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->firstOrFail();

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' invite cancelled', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_invite_cancelled'));
    }

    // ── Accept Invitation ──────────────────────────────

    /**
     * Accept a pending invitation for the authenticated user.
     * Validates the user is the invited participant and checks capacity.
     */
    public function acceptInvitation(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();
        $authUser = Auth::user();

        // Must be the invited user
        if ($participant->user_id !== $authUser->id) {
            session()->flash('error', __('people.error_not_your_invitation'));

            return;
        }

        // Must have invited role and pending status
        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('people.error_invitation_no_longer_valid'));

            return;
        }

        // Check capacity
        $currentCount = $entity->participants()
            ->where('status', 'approved')
            ->count();

        if ($entity->max_players && $currentCount >= $entity->max_players) {
            session()->flash('error', __('people.error_entity_full', ['entity' => strtolower($this->getEntityName())]));

            return;
        }

        $participant->update([
            'role' => 'player',
            'status' => 'approved',
        ]);

        Log::info($this->getEntityName() . ' invitation accepted', [
            $entityIdColumn => $entity->id,
            'user_id' => $authUser->id,
        ]);

        session()->flash('success', __('people.flash_invitation_accepted'));
    }

    /**
     * Decline a pending invitation for the authenticated user.
     */
    public function declineInvitation(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();
        $authUser = Auth::user();

        // Must be the invited user
        if ($participant->user_id !== $authUser->id) {
            session()->flash('error', __('people.error_not_your_invitation'));

            return;
        }

        // Must have invited role and pending status
        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('people.error_invitation_no_longer_valid'));

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' invitation declined', [
            $entityIdColumn => $entity->id,
            'user_id' => $authUser->id,
        ]);

        session()->flash('success', __('common.flash_invite_declined'));
    }

    // ── Helpers ────────────────────────────────────────

    private function findParticipant(string $participantId)
    {
        $participantModel = $this->getParticipantModel();
        $entity = $this->getEntity();

        return $participantModel::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->firstOrFail();
    }
}
