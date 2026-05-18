<?php

namespace App\Traits;

use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Mail\EntityInvitationEmail;
use App\Models\SuppressedInviteEmail;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Services\BenchService;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->authorize('update', $this->getEntity());

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
                Log::warning($this->getEntityName().' invite skipped: user not found', [
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
                Log::warning($this->getEntityName().' invite skipped: not a friend', [
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

            Log::info($this->getEntityName().' participant invited', [
                $entityIdColumn => $entity->id,
                'invited_user_id' => $targetUser->id,
                'invited_by' => $authUser->id,
            ]);

            $this->sendInvitationNotification($targetUser);

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

        $normalizedEmail = strtolower($email);

        $rateLimitKey = 'email-invite:'.Auth::id().':'.$this->getEntity()->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $this->addError('inviteEmail', __('people.error_too_many_invite_attempts'));

            return;
        }
        RateLimiter::hit($rateLimitKey, 3600);

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

            if ($this->isAtCapacity()) {
                // Full entity — add to waitlist or bench instead of refusing
                $this->addEmailInviteeToOverflow($entity, $participantModel, $entityIdColumn, $normalizedEmail, $authUser, $existingUser->id);

                return;
            }

            $participantModel::create([
                $entityIdColumn => $entity->id,
                'user_id' => $existingUser->id,
                'role' => 'invited',
                'status' => 'pending',
                'join_source' => JoinSource::EmailInvite,
            ]);

            Log::info($this->getEntityName().' email invite: existing user invited', [
                $entityIdColumn => $entity->id,
                'invited_user_id' => $existingUser->id,
                'invited_by' => $authUser->id,
                'join_source' => 'email_invite',
            ]);

            $this->sendInvitationNotification($existingUser);

            $this->reset('inviteEmail');
            session()->flash('success', __('people.flash_email_invite_sent'));

            return;
        }

        // ── Path 4: No existing user → email-invite path ──

        // Check suppression early — before creating the participant record.
        // If suppressed, we still create the participant but without storing
        // the plaintext invitee_email (use an anonymous placeholder instead).
        $isSuppressed = SuppressedInviteEmail::isSuppressed($normalizedEmail);

        // Duplicate check: pending invite already sent to this email.
        // Application-level check first, with DB unique constraint as safety net.
        // For suppressed emails, also check the suppressed- prefixed form since
        // the stored value is the hash, not the plaintext email.
        $duplicateQuery = $participantModel::where($entityIdColumn, $entity->id)
            ->where('status', ParticipantStatus::Pending);

        if ($isSuppressed) {
            $duplicateQuery->where(function ($q) use ($normalizedEmail) {
                $q->where('invitee_email', $normalizedEmail)
                    ->orWhere('invitee_email', 'suppressed-'.SuppressedInviteEmail::hashEmail($normalizedEmail));
            });
        } else {
            $duplicateQuery->where('invitee_email', $normalizedEmail);
        }

        $existingInvite = $duplicateQuery->first();

        if ($existingInvite) {
            $this->addError('inviteEmail', __('people.error_email_invite_already_sent'));

            return;
        }

        if ($this->isAtCapacity()) {
            // Full entity — add to waitlist or bench instead of refusing
            $this->addEmailInviteeToOverflow($entity, $participantModel, $entityIdColumn, $normalizedEmail, $authUser);

            return;
        }

        if ($isSuppressed) {
            // Suppressed email — create participant without sending email.
            // Use a deterministic suppressed- prefixed hash instead of the real email.
            // Same email input always produces the same hash, so duplicate detection
            // still works. This avoids storing plaintext PII for opted-out recipients
            // (GDPR Art. 5(1)(c) data minimization).
            $suppressedEmail = 'suppressed-'.SuppressedInviteEmail::hashEmail($normalizedEmail);

            Log::info('invite.email.suppressed_skipped', [
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'invited_by' => $authUser->id,
            ]);

            try {
                $participantModel::create([
                    $entityIdColumn => $entity->id,
                    'user_id' => null,
                    'invitee_email' => $suppressedEmail,
                    'role' => 'invited',
                    'status' => 'pending',
                    'join_source' => JoinSource::EmailInvite,
                ]);
            } catch (QueryException $e) {
                $this->addError('inviteEmail', __('people.error_email_invite_already_sent'));

                return;
            }

            $this->reset('inviteEmail');
            session()->flash('success', __('people.flash_email_invite_sent'));

            return;
        }

        // Create participant — catch QueryException for the race condition
        // where two requests pass the app-level check simultaneously.
        // The partial unique index on (entity_id, invitee_email) is the final guard.
        try {
            $participantModel::create([
                $entityIdColumn => $entity->id,
                'user_id' => null,
                'invitee_email' => $normalizedEmail,
                'role' => 'invited',
                'status' => 'pending',
                'join_source' => JoinSource::EmailInvite,
            ]);
        } catch (QueryException $e) {
            Log::warning('email_invite.duplicate_detected', [
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
            ]);
            $this->addError('inviteEmail', __('people.error_email_invite_already_sent'));

            return;
        }

        Log::info($this->getEntityName().' email invite: external email invited', [
            $entityIdColumn => $entity->id,
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'invited_by' => $authUser->id,
            'join_source' => 'email_invite',
        ]);

        // Send invitation email (suppression already checked above)
        try {
            // Rate limit unique invitee emails per sender (5 per 24h)
            $senderRateKey = 'invite-email-unique:'.$authUser->id.':'.$normalizedEmail;
            if (RateLimiter::tooManyAttempts($senderRateKey, 5)) {
                Log::warning('invite.email.rate_limited', [
                    'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    'entity_type' => $this->getEntityName(),
                    $entityIdColumn => $entity->id,
                    'invited_by' => $authUser->id,
                ]);
                // Skip sending but keep the participant record
            } else {
                RateLimiter::hit($senderRateKey, 86400); // 24 hours

                $mailable = new EntityInvitationEmail(
                    entityType: strtolower($this->getEntityName()),
                    entityName: $entity->name,
                    entityDateTime: $entity->date_time ?? null,
                    entityLocation: $entity->linkedLocation?->address ?? null,
                    inviterName: $authUser->name,
                    inviteeEmail: $normalizedEmail,
                    signupUrl: route('register', ['locale' => app()->getLocale()]),
                    optoutUrl: route('invite.optout.show', [
                        'locale' => app()->getLocale(),
                        'emailHash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    ]),
                );
                Mail::to($normalizedEmail)->queue($mailable);
            }
        } catch (\Throwable $e) {
            Log::error('email.invite_delivery_failed', [
                'entity_type' => $this->getEntityName(),
                $entityIdColumn => $entity->id,
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
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

        Log::info($this->getEntityName().' application approved', [
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

        Log::info($this->getEntityName().' application rejected', [
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

        Log::info($this->getEntityName().' participant removed', [
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

        Log::info($this->getEntityName().' invite cancelled', [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $userId,
            'invitee_email_hash' => $inviteeEmail ? SuppressedInviteEmail::hashEmail($inviteeEmail) : null,
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

        // Guard: entity must not be cancelled/canceled or completed
        $inactiveStatuses = ['canceled', 'cancelled', 'completed'];
        if ($entity->status && in_array($entity->status->value, $inactiveStatuses)) {
            session()->flash('error', __('people.error_entity_no_longer_available'));

            return;
        }

        // Must be the invited user
        if ($participant->user_id !== $authUser->id) {
            session()->flash('error', __('people.error_not_your_invitation'));

            return;
        }

        // Must have invited role and pending status
        if ($participant->role !== 'invited' || $participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('people.error_invitation_no_longer_valid'));

            return;
        }

        // Check capacity atomically to prevent over-acceptance under concurrency.
        // Lock the entity row for the entire capacity-check + participant-update
        // sequence so two simultaneous accepts cannot both pass the count check.
        $entity = $this->getEntity();

        $overflowed = DB::transaction(function () use ($entity, $participant, $authUser) {
            $lockedEntity = $entity->newModelQuery()
                ->where('id', $entity->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved)
                ->count();

            if ($lockedEntity->max_players && $currentCount >= $lockedEntity->max_players) {
                // Signal overflow — handled outside the transaction.
                return true;
            }

            $participant->update([
                'role' => 'player',
                'status' => 'approved',
            ]);

            return false;
        });

        if ($overflowed) {
            $this->addAcceptedInviteeToOverflow($participant, $entity);

            return;
        }

        Log::info($this->getEntityName().' invitation accepted', [
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
        if ($participant->role !== 'invited' || $participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('people.error_invitation_no_longer_valid'));

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info($this->getEntityName().' invitation declined', [
            $entityIdColumn => $entity->id,
            'user_id' => $authUser->id,
        ]);

        session()->flash('success', __('common.flash_invite_declined'));
    }

    // ── Waitlist / Bench Getters ───────────────────────

    /**
     * Get all waitlisted participants ordered by queue position.
     */
    public function getWaitlistedParticipants()
    {
        $entity = $this->getEntity();

        return $entity->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->orderBy('waitlisted_at', 'asc')
            ->with('user')
            ->get();
    }

    /**
     * Get all benched participants ordered by bench time.
     */
    public function getBenchedParticipants()
    {
        $entity = $this->getEntity();

        return $entity->participants()
            ->where('status', ParticipantStatus::Benched->value)
            ->orderBy('benched_at', 'asc')
            ->with('user')
            ->get();
    }

    // ── Waitlist / Bench Actions ───────────────────────
    // Prefixed with "manage" to avoid collisions with HandlesBench::promoteFromBench()
    // and HandlesWaitlist::manualPromote() used by GameDetail.

    /**
     * Promote a waitlisted participant to approved status.
     * Delegates to WaitlistService::manuallyPromote().
     */
    public function managePromoteFromWaitlist(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            session()->flash('error', __('common.error_participant_not_waitlisted'));

            return;
        }

        app(WaitlistService::class)->manuallyPromote($participant);

        Log::info($this->getEntityName().' waitlist participant promoted', [
            $entityIdColumn => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'promoted_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_waitlist_promoted'));
    }

    /**
     * Remove a waitlisted participant (sets status to Rejected).
     */
    public function manageRemoveFromWaitlist(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            session()->flash('error', __('common.error_participant_not_waitlisted'));

            return;
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($this->getEntityName().' waitlist participant removed', [
            $entityIdColumn => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_waitlist_removed'));
    }

    /**
     * Promote a benched participant to approved status.
     * Delegates to BenchService::promoteFromBench().
     */
    public function managePromoteFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->status !== ParticipantStatus::Benched) {
            session()->flash('error', __('common.error_participant_not_benched'));

            return;
        }

        $entityType = $this->getEntityName() === 'Game' ? 'game' : 'campaign';

        app(BenchService::class)->promoteFromBench($participantId, $entityType);

        Log::info($this->getEntityName().' bench participant promoted', [
            $entityIdColumn => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'promoted_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_bench_promoted'));
    }

    /**
     * Remove a benched participant (sets status to Rejected).
     */
    public function manageRemoveFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipant($participantId);
        $entity = $this->getEntity();
        $entityIdColumn = $this->getEntityIdColumn();

        if ($participant->status !== ParticipantStatus::Benched) {
            session()->flash('error', __('common.error_participant_not_benched'));

            return;
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($this->getEntityName().' bench participant removed', [
            $entityIdColumn => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_bench_removed'));
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Returns true if the entity has reached max_players capacity.
     * Counts only approved participants.
     */
    private function isAtCapacity(): bool
    {
        $entity = $this->getEntity();

        if (! $entity->max_players) {
            return false;
        }

        return $entity->participants()
            ->where('status', ParticipantStatus::Approved)
            ->count() >= $entity->max_players;
    }

    /**
     * Sends an in-app invitation notification to the given user.
     * Swallows errors and logs them so a notification failure never breaks the invite flow.
     */
    private function sendInvitationNotification(User $user): void
    {
        $entity = $this->getEntity();
        $authUser = Auth::user();

        try {
            $notificationClass = $this->getEntityName() === 'Game'
                ? new GameInvitation($entity, $authUser)
                : new CampaignInvitation($entity, $authUser);
            $category = $this->getEntityName() === 'Game'
                ? NotificationCategory::GameInvitation
                : NotificationCategory::CampaignInvitation;

            app(NotificationService::class)->send($user, $notificationClass, $category);
        } catch (\Throwable $e) {
            Log::error('notification.invite_dispatch_failed', [
                'entity_type' => $this->getEntityName(),
                $this->getEntityIdColumn() => $entity->id,
                'target_user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine the overflow status for a full entity.
     *
     * Non-bench-mode entities (traditional waitlist): overflow to Waitlisted (FIFO queue).
     * Bench-mode entities: overflow to Benched (host-controlled promotion).
     *
     * @return array{status: string, timestamp_column: string, timestamp_value: Carbon}
     */
    private function resolveOverflowStatus(): array
    {
        $entity = $this->getEntity();

        if (! $entity->isBenchMode()) {
            return [
                'status' => ParticipantStatus::Waitlisted->value,
                'timestamp_column' => 'waitlisted_at',
            ];
        }

        return [
            'status' => ParticipantStatus::Benched->value,
            'timestamp_column' => 'benched_at',
        ];
    }

    /**
     * Add an email invitee to the waitlist or bench when the entity is full.
     * Handles both existing-user and non-existing-user paths.
     *
     * For standalone games: creates a waitlisted participant.
     * For campaigns/campaign sessions: creates a benched participant.
     */
    private function addEmailInviteeToOverflow(
        $entity,
        string $participantModel,
        string $entityIdColumn,
        string $normalizedEmail,
        $authUser,
        ?string $existingUserId = null,
    ): void {
        $overflow = $this->resolveOverflowStatus();

        $data = [
            $entityIdColumn => $entity->id,
            'user_id' => $existingUserId,
            'invitee_email' => $existingUserId ? null : $normalizedEmail,
            'role' => 'invited',
            'status' => $overflow['status'],
            'join_source' => JoinSource::EmailInvite,
            $overflow['timestamp_column'] => now(),
        ];

        $participantModel::create($data);

        $logContext = [
            'entity_type' => $this->getEntityName(),
            $entityIdColumn => $entity->id,
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'invited_by' => $authUser->id,
            'overflow_status' => $overflow['status'],
        ];
        if ($existingUserId) {
            $logContext['invited_user_id'] = $existingUserId;
        }
        Log::info($this->getEntityName().' email invite added to '.$overflow['status'], $logContext);

        // Still send the invitation email/notification so the person knows they've been invited
        // (they'll be notified when promoted from waitlist/bench later)
        if ($existingUserId) {
            $existingUser = User::find($existingUserId);
            if ($existingUser) {
                $this->sendInvitationNotification($existingUser);
            }
        } else {
            try {
                // Check suppression before sending overflow invite
                if (SuppressedInviteEmail::isSuppressed($normalizedEmail)) {
                    Log::info('invite.email.suppressed_overflow', [
                        'entity_type' => $this->getEntityName(),
                        $entityIdColumn => $entity->id,
                        'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                        'invited_by' => $authUser->id,
                    ]);
                } else {
                    $mailable = new EntityInvitationEmail(
                        entityType: strtolower($this->getEntityName()),
                        entityName: $entity->name,
                        entityDateTime: $entity->date_time ?? null,
                        entityLocation: $entity->linkedLocation?->address ?? null,
                        inviterName: $authUser->name,
                        inviteeEmail: $normalizedEmail,
                        signupUrl: route('register', ['locale' => app()->getLocale()]),
                        optoutUrl: route('invite.optout.show', [
                            'locale' => app()->getLocale(),
                            'emailHash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                        ]),
                    );
                    Mail::to($normalizedEmail)->queue($mailable);
                }
            } catch (\Throwable $e) {
                Log::error('email.invite_delivery_failed', [
                    'entity_type' => $this->getEntityName(),
                    $entityIdColumn => $entity->id,
                    'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->reset('inviteEmail');

        $messageKey = $overflow['status'] === ParticipantStatus::Waitlisted->value
            ? 'people.flash_email_invite_waitlisted'
            : 'people.flash_email_invite_benched';
        session()->flash('success', __($messageKey));
    }

    /**
     * Move an accepted invitee to waitlist or bench when the entity is full.
     * Called from acceptInvitation() when capacity check fails.
     */
    private function addAcceptedInviteeToOverflow($participant, $entity): void
    {
        $overflow = $this->resolveOverflowStatus();

        $participant->update([
            'status' => $overflow['status'],
            $overflow['timestamp_column'] => now(),
        ]);

        Log::info($this->getEntityName().' invitation accepted but entity full — moved to '.$overflow['status'], [
            $this->getEntityIdColumn() => $entity->id,
            'user_id' => $participant->user_id,
            'overflow_status' => $overflow['status'],
        ]);

        $messageKey = $overflow['status'] === ParticipantStatus::Waitlisted->value
            ? 'people.flash_accepted_waitlisted'
            : 'people.flash_accepted_benched';
        session()->flash('success', __($messageKey));
    }

    private function findParticipant(string $participantId)
    {
        $participantModel = $this->getParticipantModel();
        $entity = $this->getEntity();

        return $participantModel::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->firstOrFail();
    }
}
