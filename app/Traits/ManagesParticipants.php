<?php

namespace App\Traits;

use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Mail\EntityInvitationEmail;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
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

    /** @var string Email address for email-based invitations */
    public string $inviteEmail = '';

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
            $userId = (string) $userId;
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
                'join_source' => JoinSource::FriendInvite,
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
     * Invite someone by email address.
     *
     * Three code paths:
     *  1. Invalid email → error, return
     *  2. Self-invite → error, return
     *  3. Existing user → friend-invite path (participant + notification)
     *  4. No account → email-invite path (participant with invitee_email, send mailable)
     */
    public function inviteByEmail(): void
    {
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

        $normalizedEmail = strtolower($email);
        $authUser = Auth::user();
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();
        $participantModel = $this->getParticipantModel();

        // Self-invite check
        if ($normalizedEmail === strtolower($authUser->email)) {
            $this->addError('inviteEmail', __('people.error_cannot_invite_self'));

            return;
        }

        // Check if the email belongs to an existing user
        $existingUser = User::where('email', $normalizedEmail)->first();

        if ($existingUser) {
            // ── Path 3: Existing user → friend-invite-style path ──

            // Already a participant?
            if ($entity->participants()->where('user_id', $existingUser->id)->exists()) {
                $this->addError('inviteEmail', __('people.error_user_already_participant'));

                return;
            }

            // Capacity check
            if ($entity->max_players) {
                $currentCount = $entity->participants()
                    ->where('status', ParticipantStatus::Approved)
                    ->count();

                if ($currentCount >= $entity->max_players) {
                    $this->addError('inviteEmail', __('people.error_entity_full', ['entity' => strtolower($this->getEntityName())]));

                    return;
                }
            }

            $participantModel::create([
                $entityIdColumn => $entity->id,
                'user_id' => $existingUser->id,
                'role' => 'invited',
                'status' => 'pending',
                'join_source' => JoinSource::EmailInvite,
            ]);

            Log::info($this->getEntityName() . ' email invite: existing user invited', [
                $entityIdColumn => $entity->id,
                'invited_user_id' => $existingUser->id,
                'invited_by' => $authUser->id,
                'join_source' => 'email_invite',
            ]);

            // Send in-app notification (same as friend invite)
            try {
                $notificationClass = $this->getEntityName() === 'Game'
                    ? new GameInvitation($entity, $authUser)
                    : new CampaignInvitation($entity, $authUser);
                $category = $this->getEntityName() === 'Game'
                    ? NotificationCategory::GameInvitation
                    : NotificationCategory::CampaignInvitation;

                app(NotificationService::class)->send($existingUser, $notificationClass, $category);
            } catch (\Throwable $e) {
                Log::error('notification.email_invite_dispatch_failed', [
                    'entity_type' => $this->getEntityName(),
                    $entityIdColumn => $entity->id,
                    'target_user_id' => $existingUser->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->reset('inviteEmail');
            session()->flash('success', __('people.flash_email_invite_sent'));

            return;
        }

        // ── Path 4: No existing user → email-invite path ──

        // Duplicate check: pending invite already sent to this email
        $existingInvite = $participantModel::where($entityIdColumn, $entity->id)
            ->where('invitee_email', $normalizedEmail)
            ->where('status', ParticipantStatus::Pending)
            ->first();

        if ($existingInvite) {
            $this->addError('inviteEmail', __('people.error_email_invite_already_sent'));

            return;
        }

        // Capacity check
        if ($entity->max_players) {
            $currentCount = $entity->participants()
                ->where('status', ParticipantStatus::Approved)
                ->count();

            if ($currentCount >= $entity->max_players) {
                $this->addError('inviteEmail', __('people.error_entity_full', ['entity' => strtolower($this->getEntityName())]));

                return;
            }
        }

        // Create participant with null user_id and invitee_email
        $participantModel::create([
            $entityIdColumn => $entity->id,
            'user_id' => null,
            'invitee_email' => $normalizedEmail,
            'role' => 'invited',
            'status' => 'pending',
            'join_source' => JoinSource::EmailInvite,
        ]);

        Log::info($this->getEntityName() . ' email invite: external email invited', [
            $entityIdColumn => $entity->id,
            'invitee_email' => $normalizedEmail,
            'invited_by' => $authUser->id,
            'join_source' => 'email_invite',
        ]);

        // Send invitation email
        try {
            $mailable = new EntityInvitationEmail(
                entityType: strtolower($this->getEntityName()),
                entityName: $entity->name ?? $entity->title,
                entityDateTime: $entity->date_time ?? null,
                entityLocation: $entity->linkedLocation?->address ?? null,
                inviterName: $authUser->name,
                inviteeEmail: $normalizedEmail,
                signupUrl: url('/register'),
            );
            Mail::to($normalizedEmail)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('email.invite_delivery_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'invitee_email' => $normalizedEmail,
                'error' => $e->getMessage(),
            ]);
            // Keep the participant record even if email fails
        }

        $this->reset('inviteEmail');
        session()->flash('success', __('people.flash_email_invite_sent'));
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
            'join_source' => JoinSource::Application,
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

        $removedUser = User::find($participant->user_id);

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' participant removed', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        // Notify removed user
        try {
            if ($removedUser) {
                $entityType = strtolower($this->getEntityName());
                app(NotificationService::class)->send(
                    $removedUser,
                    new ParticipantRemoved($removedUser, $entity, $entityType),
                    NotificationCategory::ParticipantRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_removed_dispatch_failed', [
                'entity_type' => $this->getEntityName(),
                $this->getEntityIdColumn() => $entity->id,
                'removed_user_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

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

        $inviteeEmail = $participant->invitee_email;
        $userId = $participant->user_id;

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName() . ' invite cancelled', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $userId,
            'invitee_email' => $inviteeEmail,
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
        if ($participant->role !== 'invited' || $participant->status !== \App\Enums\ParticipantStatus::Pending) {
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

        // Notify entity owner that a participant joined
        try {
            $owner = User::find($entity->owner_id);
            if ($owner && $owner->id !== $authUser->id) {
                $entityType = strtolower($this->getEntityName());
                app(NotificationService::class)->send(
                    $owner,
                    new ParticipantJoined($authUser, $entity, $entityType),
                    NotificationCategory::ParticipantJoined
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_joined_dispatch_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'participant_id' => $authUser->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark the related invitation notification as read
        try {
            $invitationType = $this->getEntityName() === 'Game'
                ? GameInvitation::class
                : CampaignInvitation::class;
            $dataKey = $this->getEntityName() === 'Game' ? 'game_id' : 'campaign_id';
            app(NotificationService::class)->markReadByType($authUser, $invitationType, $entity->id, $dataKey);
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_on_accept_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'user_id' => $authUser->id,
                'error' => $e->getMessage(),
            ]);
        }

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
        if ($participant->role !== 'invited' || $participant->status !== \App\Enums\ParticipantStatus::Pending) {
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
