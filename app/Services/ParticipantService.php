<?php

namespace App\Services;

use App\Dto\EntityMeta;
use App\Dto\InviteBatchResult;
use App\Dto\ParticipantResult;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Mail\EntityInvitationEmail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SuppressedInviteEmail;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\EntityInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class ParticipantService
{
    // ── Entity metadata helper ─────────────────────────

    /**
     * Resolve metadata for a Game|Campaign.
     *
     * Centralizes the repeated instanceof checks for logging, querying,
     * and participant model resolution. If a third entity type is added,
     */
    private function entityMeta(Game|Campaign $entity): EntityMeta
    {
        return EntityMeta::fromEntity($entity);
    }

    // ── Invite Friends ─────────────────────────────────

    /**
     * Invite selected friends as participants.
     *
     * Validates mutual friendship, skips duplicates/self-invites/stale IDs.
     *
     * @param  string[]  $friendUserIds  UUID user IDs
     */
    public function inviteFriends(Game|Campaign $entity, User $inviter, array $friendUserIds): InviteBatchResult
    {
        $meta = $this->entityMeta($entity);
        $invitedCount = 0;
        $skippedCount = 0;

        foreach ($friendUserIds as $userId) {
            $userId = (string) $userId;
            $targetUser = User::find($userId);

            if (! $targetUser) {
                $skippedCount++;
                Log::warning($meta->type.' invite skipped: user not found', [
                    $meta->foreignKey => $entity->id,
                    'target_user_id' => $userId,
                ]);

                continue;
            }

            if ($targetUser->id === $inviter->id) {
                $skippedCount++;

                continue;
            }

            if (! $inviter->isFriend($targetUser)) {
                $skippedCount++;
                Log::warning($meta->type.' invite skipped: not a friend', [
                    $meta->foreignKey => $entity->id,
                    'target_user_id' => $targetUser->id,
                    'invited_by' => $inviter->id,
                ]);

                continue;
            }

            if ($entity->participants()->where('user_id', $targetUser->id)->exists()) {
                $skippedCount++;

                continue;
            }

            try {
                $meta->participantClass::create([
                    $meta->foreignKey => $entity->id,
                    'user_id' => $targetUser->id,
                    'role' => ParticipantRole::Invited->value,
                    'status' => ParticipantStatus::Pending->value,
                    'join_source' => JoinSource::FriendInvite,
                ]);
            } catch (QueryException) {
                // Concurrent request won the race — unique constraint on (entity_id, user_id)
                $skippedCount++;
                Log::info($meta->type.' invite skipped: duplicate caught by unique constraint', [
                    $meta->foreignKey => $entity->id,
                    'invited_user_id' => $targetUser->id,
                    'invited_by' => $inviter->id,
                ]);

                continue;
            }

            Log::info($meta->type.' participant invited', [
                $meta->foreignKey => $entity->id,
                'invited_user_id' => $targetUser->id,
                'invited_by' => $inviter->id,
            ]);

            $this->sendInvitationNotification($entity, $targetUser, $inviter);

            $invitedCount++;
        }

        return new InviteBatchResult($invitedCount, $skippedCount);
    }

    // ── Invite by Email ────────────────────────────────

    /**
     * Invite someone by email address.
     *
     * Three code paths:
     *  1. Existing user → friend-invite path (participant + notification)
     *  2. No account, suppressed → participant without sending email
     *  3. No account → email-invite path (participant with invitee_email, send mailable)
     */
    public function inviteByEmail(Game|Campaign $entity, User $inviter, string $email): ParticipantResult
    {
        $meta = $this->entityMeta($entity);
        $normalizedEmail = strtolower(trim($email));

        // Self-invite check
        if ($normalizedEmail === strtolower($inviter->email)) {
            return ParticipantResult::fail('people.error_cannot_invite_self');
        }

        // Rate limit: 10 email invites per user per entity per hour
        $rateLimitKey = 'email-invite:'.$inviter->id.':'.$entity->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ParticipantResult::fail('people.error_too_many_invite_attempts');
        }
        RateLimiter::hit($rateLimitKey, 3600);

        // Check if the email belongs to an existing user
        $existingUser = User::where('email', $normalizedEmail)->first();

        if ($existingUser) {
            return $this->inviteExistingUserByEmail($entity, $inviter, $existingUser, $normalizedEmail, $meta);
        }

        return $this->inviteExternalEmail($entity, $inviter, $normalizedEmail, $meta);
    }

    /**
     * Handle email invite for an existing registered user.
     */
    private function inviteExistingUserByEmail(
        Game|Campaign $entity,
        User $inviter,
        User $existingUser,
        string $normalizedEmail,
        EntityMeta $meta,
    ): ParticipantResult {
        // Already a participant?
        if ($entity->participants()->where('user_id', $existingUser->id)->exists()) {
            return ParticipantResult::fail('people.error_user_already_participant');
        }

        if ($this->isAtCapacity($entity)) {
            $this->addEmailInviteeToOverflow($entity, $meta, $normalizedEmail, $inviter, $existingUser->id);

            return $this->overflowFlashResult($entity);
        }

        $meta->participantClass::create([
            $meta->foreignKey => $entity->id,
            'user_id' => $existingUser->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::EmailInvite,
        ]);

        Log::info($meta->type.' email invite: existing user invited', [
            $meta->foreignKey => $entity->id,
            'invited_user_id' => $existingUser->id,
            'invited_by' => $inviter->id,
            'join_source' => 'email_invite',
        ]);

        $this->sendInvitationNotification($entity, $existingUser, $inviter);

        return ParticipantResult::ok('people.flash_email_invite_sent');
    }

    /**
     * Handle email invite for a non-registered email address.
     */
    private function inviteExternalEmail(
        Game|Campaign $entity,
        User $inviter,
        string $normalizedEmail,
        EntityMeta $meta,
    ): ParticipantResult {
        $isSuppressed = SuppressedInviteEmail::isSuppressed($normalizedEmail);

        // Duplicate check: pending invite already sent to this email
        $duplicateQuery = $meta->participantClass::where($meta->foreignKey, $entity->id)
            ->where('status', ParticipantStatus::Pending);

        if ($isSuppressed) {
            $duplicateQuery->where(function ($q) use ($normalizedEmail) {
                $q->where('invitee_email', $normalizedEmail)
                    ->orWhere('invitee_email', 'suppressed-'.SuppressedInviteEmail::hashEmail($normalizedEmail));
            });
        } else {
            $duplicateQuery->where('invitee_email', $normalizedEmail);
        }

        if ($duplicateQuery->first()) {
            return ParticipantResult::fail('people.error_email_invite_already_sent');
        }

        if ($this->isAtCapacity($entity)) {
            $this->addEmailInviteeToOverflow($entity, $meta, $normalizedEmail, $inviter);

            return $this->overflowFlashResult($entity);
        }

        if ($isSuppressed) {
            return $this->inviteSuppressedEmail($entity, $inviter, $normalizedEmail, $meta);
        }

        return $this->inviteNormalExternalEmail($entity, $inviter, $normalizedEmail, $meta);
    }

    /**
     * Create participant for suppressed email without sending.
     */
    private function inviteSuppressedEmail(
        Game|Campaign $entity,
        User $inviter,
        string $normalizedEmail,
        EntityMeta $meta,
    ): ParticipantResult {
        $suppressedEmail = 'suppressed-'.SuppressedInviteEmail::hashEmail($normalizedEmail);

        Log::info('invite.email.suppressed_skipped', [
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'entity_type' => $meta->type,
            $meta->foreignKey => $entity->id,
            'invited_by' => $inviter->id,
        ]);

        try {
            $meta->participantClass::create([
                $meta->foreignKey => $entity->id,
                'user_id' => null,
                'invitee_email' => $suppressedEmail,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::EmailInvite,
            ]);
        } catch (QueryException) {
            return ParticipantResult::fail('people.error_email_invite_already_sent');
        }

        return ParticipantResult::ok('people.flash_email_invite_sent');
    }

    /**
     * Create participant for normal external email and send invitation mailable.
     */
    private function inviteNormalExternalEmail(
        Game|Campaign $entity,
        User $inviter,
        string $normalizedEmail,
        EntityMeta $meta,
    ): ParticipantResult {
        try {
            $meta->participantClass::create([
                $meta->foreignKey => $entity->id,
                'user_id' => null,
                'invitee_email' => $normalizedEmail,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::EmailInvite,
            ]);
        } catch (QueryException) {
            Log::warning('email_invite.duplicate_detected', [
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
            ]);

            return ParticipantResult::fail('people.error_email_invite_already_sent');
        }

        Log::info($meta->type.' email invite: external email invited', [
            $meta->foreignKey => $entity->id,
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'invited_by' => $inviter->id,
            'join_source' => 'email_invite',
        ]);

        // Send invitation email
        $this->sendExternalInvitationEmail($entity, $inviter, $normalizedEmail, $meta);

        return ParticipantResult::ok('people.flash_email_invite_sent');
    }

    /**
     * Send the EntityInvitationEmail mailable to an external email address.
     * Handles rate limiting and suppression gracefully.
     */
    private function sendExternalInvitationEmail(
        Game|Campaign $entity,
        User $inviter,
        string $normalizedEmail,
        EntityMeta $meta,
    ): void {
        try {
            $senderRateKey = 'invite-email-unique:'.$inviter->id.':'.$normalizedEmail;
            if (RateLimiter::tooManyAttempts($senderRateKey, 5)) {
                Log::warning('invite.email.rate_limited', [
                    'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    'entity_type' => $meta->type,
                    $meta->foreignKey => $entity->id,
                    'invited_by' => $inviter->id,
                ]);

                // Skip sending but keep the participant record
                return;
            }

            RateLimiter::hit($senderRateKey, 86400);

            $mailable = new EntityInvitationEmail(
                entityType: strtolower($meta->type),
                entityName: $entity->name,
                entityDateTime: $entity->date_time ?? null,
                entityLocation: $entity->linkedLocation->address ?? null,
                inviterName: $inviter->name,
                inviteeEmail: $normalizedEmail,
                signupUrl: route('register', ['locale' => app()->getLocale()]),
                optoutUrl: route('invite.optout.show', [
                    'locale' => app()->getLocale(),
                    'emailHash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                ]),
            );
            Mail::to($normalizedEmail)->queue($mailable);
        } catch (\Throwable $e) {
            Log::error('email.invite_delivery_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Application Management ─────────────────────────

    /**
     * Approve a participant's application.
     */
    public function approveApplication(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $approver,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        // Check for a pending application rather than relying on participant role,
        // since public games create participants with role='player' even when
        // the application record is still pending (e.g. pre-fix data).
        $pendingApplication = $entity->applications()
            ->where('user_id', $participant->user_id)
            ->where('status', 'pending')
            ->exists();

        if (! $pendingApplication) {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        $participant->update([
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Application,
        ]);

        $entity->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => ParticipantStatus::Approved->value]);

        Log::info($meta->type.' application approved', [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->user_id,
            'approved_by' => $approver->id,
        ]);

        // Notify applicant
        try {
            $applicant = User::find($participant->user_id);
            if ($applicant) {
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationApproved($entity, strtolower($meta->type), $approver),
                    NotificationCategory::ApplicationApproved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_approved_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'applicant_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_application_approved');
    }

    /**
     * Reject a participant's application.
     */
    public function rejectApplication(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $rejecter,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        $pendingApplication = $entity->applications()
            ->where('user_id', $participant->user_id)
            ->where('status', 'pending')
            ->exists();

        if (! $pendingApplication) {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        $rejectedUserId = $participant->user_id;

        // Delete both records so the user can re-apply later if they want
        $participant->delete();
        $entity->applications()->where('user_id', $rejectedUserId)->delete();

        Log::info($meta->type.' application rejected', [
            $meta->foreignKey => $entity->id,
            'user_id' => $rejectedUserId,
            'rejected_by' => $rejecter->id,
        ]);

        // Notify applicant
        try {
            $applicant = User::find($rejectedUserId);
            if ($applicant) {
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationRejected($entity, strtolower($meta->type), $rejecter),
                    NotificationCategory::ApplicationRejected
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_rejected_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'applicant_id' => $rejectedUserId,
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_application_rejected');
    }

    // ── Remove Participant ─────────────────────────────

    /**
     * Remove a participant (sets status to rejected, sends notification).
     */
    public function removeParticipant(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $remover,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        if ($participant->role === ParticipantRole::Owner) {
            return ParticipantResult::fail('common.error_cannot_remove_the_entity_owner', [
                'entity' => strtolower($meta->type),
            ]);
        }

        $removedUser = User::find($participant->user_id);
        $removedUserId = $participant->user_id;

        // Soft-remove: set status to 'removed' instead of hard-deleting.
        // This preserves roster history so we can detect hosts who kick everyone
        // then cancel to dodge penalties (peak-roster counting in AttendanceService).
        // The unique constraint on (game_id/campaign_id, user_id) blocks the
        // removed user from re-applying — the participant record persists.
        // Application record is cleaned up below for hygiene only.
        $participant->update([
            'status' => ParticipantStatus::Removed->value,
            'removed_by' => $remover->id,
            'removed_at' => now(),
        ]);

        // Clean up application record
        $entity->applications()->where('user_id', $removedUserId)->delete();

        Log::info($meta->type.' participant removed', [
            $meta->foreignKey => $entity->id,
            'user_id' => $removedUserId,
            'removed_by' => $remover->id,
        ]);

        // Notify removed user
        try {
            if ($removedUser) {
                app(NotificationService::class)->send(
                    $removedUser,
                    new ParticipantRemoved($removedUser, $entity, strtolower($meta->type)),
                    NotificationCategory::ParticipantRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_removed_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'removed_user_id' => $removedUserId,
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_participant_removed');
    }

    // ── Cancel Invite ──────────────────────────────────

    /**
     * Cancel a pending invitation.
     */
    public function cancelInvite(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $canceller,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        $participant->delete();

        Log::info($meta->type.' invite cancelled', [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->user_id,
            'invitee_email_hash' => $participant->invitee_email
                ? SuppressedInviteEmail::hashEmail($participant->invitee_email)
                : null,
            'cancelled_by' => $canceller->id,
        ]);

        return ParticipantResult::ok('common.flash_invite_cancelled');
    }

    // ── Accept / Decline Invitation ────────────────────

    /**
     * Accept a pending invitation for the given user.
     *
     * Handles capacity overflow by moving to waitlist/bench.
     *
     * @param  User  $user  The accepting user (must match participant.user_id)
     */
    public function acceptInvitation(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $user,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        // Guard: entity must not be cancelled/canceled or completed
        $inactiveStatuses = ['canceled', 'cancelled', 'completed'];
        if ($entity->status && in_array($entity->status->value, $inactiveStatuses)) {
            return ParticipantResult::fail('people.error_entity_no_longer_available');
        }

        // Must be the invited user
        if ($participant->user_id !== $user->id) {
            return ParticipantResult::fail('people.error_not_your_invitation');
        }

        // Must have invited role and pending status
        if ($participant->role !== ParticipantRole::Invited || $participant->status !== ParticipantStatus::Pending) {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        // Check capacity atomically (owner counts as a player)
        $overflowed = DB::transaction(function () use ($entity, $participant) {
            $lockedEntity = $entity->newModelQuery()
                ->where('id', $entity->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Owner is an explicit participant, so count includes them naturally
            $currentCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved)
                ->count();

            if ($lockedEntity->max_players && $currentCount >= $lockedEntity->max_players) {
                return true;
            }

            $participant->update([
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            return false;
        });

        if ($overflowed) {
            $this->addAcceptedInviteeToOverflow($participant, $entity, $meta);
            $this->notifyOwnerOfAcceptedInvitation($entity, $user, $meta);
            $this->markInvitationNotificationRead($entity, $user, $meta);

            return $this->overflowFlashResult($entity);
        }

        Log::info($meta->type.' invitation accepted', [
            $meta->foreignKey => $entity->id,
            'user_id' => $user->id,
        ]);

        $this->notifyOwnerOfAcceptedInvitation($entity, $user, $meta);
        $this->markInvitationNotificationRead($entity, $user, $meta);

        return ParticipantResult::ok('people.flash_invitation_accepted');
    }

    /**
     * Decline a pending invitation for the given user.
     *
     * @param  User  $user  The declining user (must match participant.user_id)
     */
    public function declineInvitation(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $user,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        // Must be the invited user
        if ($participant->user_id !== $user->id) {
            return ParticipantResult::fail('people.error_not_your_invitation');
        }

        // Must have invited role and pending status
        if ($participant->role !== ParticipantRole::Invited || $participant->status !== ParticipantStatus::Pending) {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($meta->type.' invitation declined', [
            $meta->foreignKey => $entity->id,
            'user_id' => $user->id,
        ]);

        return ParticipantResult::ok('common.flash_invite_declined');
    }

    // ── Waitlist / Bench Management ────────────────────

    /**
     * Promote a waitlisted participant to approved.
     */
    public function promoteFromWaitlist(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $promoter,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            return ParticipantResult::fail('common.error_participant_not_waitlisted');
        }

        app(WaitlistService::class)->manuallyPromote($participant);

        Log::info($meta->type.' waitlist participant promoted', [
            $meta->foreignKey => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'promoted_by' => $promoter->id,
        ]);

        return ParticipantResult::ok('common.flash_waitlist_promoted');
    }

    /**
     * Remove a waitlisted participant (sets status to Rejected).
     */
    public function removeFromWaitlist(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $remover,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            return ParticipantResult::fail('common.error_participant_not_waitlisted');
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($meta->type.' waitlist participant removed', [
            $meta->foreignKey => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'removed_by' => $remover->id,
        ]);

        return ParticipantResult::ok('common.flash_waitlist_removed');
    }

    /**
     * Promote a benched participant to approved.
     */
    public function promoteFromBench(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $promoter,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        if ($participant->status !== ParticipantStatus::Benched) {
            return ParticipantResult::fail('common.error_participant_not_benched');
        }

        $entityType = $meta->isCampaign() ? 'campaign' : 'game';
        app(BenchService::class)->promoteFromBench((string) $participant->id, $entityType, $promoter);

        Log::info($meta->type.' bench participant promoted', [
            $meta->foreignKey => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'promoted_by' => $promoter->id,
        ]);

        return ParticipantResult::ok('common.flash_bench_promoted');
    }

    /**
     * Remove a benched participant (sets status to Rejected).
     */
    public function removeFromBench(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        User $remover,
    ): ParticipantResult {
        $meta = $this->entityMeta($entity);

        if ($participant->status !== ParticipantStatus::Benched) {
            return ParticipantResult::fail('common.error_participant_not_benched');
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($meta->type.' bench participant removed', [
            $meta->foreignKey => $entity->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'removed_by' => $remover->id,
        ]);

        return ParticipantResult::ok('common.flash_bench_removed');
    }

    // ── Query helpers ──────────────────────────────────

    /**
     * Get all waitlisted participants ordered by queue position.
     *
     * @return Collection<int, CampaignParticipant>|Collection<int, GameParticipant>
     */
    public function getWaitlistedParticipants(Game|Campaign $entity)
    {
        return $entity->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->orderBy('waitlisted_at', 'asc')
            ->with('user')
            ->get();
    }

    /**
     * Get all benched participants ordered by bench time.
     *
     * @return Collection<int, CampaignParticipant>|Collection<int, GameParticipant>
     */
    public function getBenchedParticipants(Game|Campaign $entity)
    {
        $benched = $entity->participants()
            ->where('status', ParticipantStatus::Benched->value)
            ->orderBy('benched_at', 'asc')
            ->with('user')
            ->get();

        return $benched;
    }

    /**
     * Find a participant by ID scoped to an entity.
     *
     *
     * @throws ModelNotFoundException
     */
    public function findParticipant(Game|Campaign $entity, string $participantId): GameParticipant|CampaignParticipant
    {
        $meta = $this->entityMeta($entity);

        return $meta->participantClass::where('id', $participantId)
            ->where($meta->foreignKey, $entity->id)
            ->firstOrFail();
    }

    /**
     * Find a pending invitation scoped to an entity (for cancelInvite).
     *
     *
     * @throws ModelNotFoundException
     */
    public function findPendingInvite(Game|Campaign $entity, string $participantId): GameParticipant|CampaignParticipant
    {
        $meta = $this->entityMeta($entity);

        return $meta->participantClass::where('id', $participantId)
            ->where($meta->foreignKey, $entity->id)
            ->where('role', ParticipantRole::Invited->value)
            ->where('status', 'pending')
            ->firstOrFail();
    }

    /**
     * Count of approved participants INCLUDING the owner.
     *
     * The owner is an explicit participant record with status=Approved,
     * so they are counted naturally by this query. Use this for capacity
     * calculations where the owner occupies a seat.
     */
    public function getApprovedParticipantCount(Game|Campaign $entity): int
    {
        return $entity->participants()
            ->where('status', ParticipantStatus::Approved)
            ->count();
    }

    /**
     * Check if the entity has reached max_players capacity.
     *
     * Accounts for the owner as a player. Returns false when max_players
     * is not set (unlimited capacity).
     */
    public function isAtCapacity(Game|Campaign $entity): bool
    {
        if (! $entity->max_players) {
            return false;
        }

        return $this->getApprovedParticipantCount($entity) >= $entity->max_players;
    }

    // ── Private helpers ────────────────────────────────

    /**
     * Send an in-app invitation notification to the given user.
     */
    private function sendInvitationNotification(Game|Campaign $entity, User $target, User $inviter): void
    {
        $meta = $this->entityMeta($entity);

        try {
            $notificationClass = new EntityInvitation($entity, $inviter);
            $category = $meta->isCampaign()
                ? NotificationCategory::CampaignInvitation
                : NotificationCategory::GameInvitation;

            app(NotificationService::class)->send($target, $notificationClass, $category);
        } catch (\Throwable $e) {
            Log::error('notification.invite_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'target_user_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the entity owner that a participant accepted their invitation.
     *
     * Called for both direct-accept and overflow (waitlist/bench) paths
     * so the owner always knows about the response.
     */
    private function notifyOwnerOfAcceptedInvitation(Game|Campaign $entity, User $acceptingUser, EntityMeta $meta): void
    {
        try {
            $owner = User::find($entity->owner_id);
            if ($owner && $owner->id !== $acceptingUser->id) {
                app(NotificationService::class)->send(
                    $owner,
                    new ParticipantJoined($acceptingUser, $entity, strtolower($meta->type)),
                    NotificationCategory::ParticipantJoined
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_joined_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'participant_id' => $acceptingUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the related invitation notification as read.
     */
    private function markInvitationNotificationRead(Game|Campaign $entity, User $user, EntityMeta $meta): void
    {
        try {
            $invitationType = EntityInvitation::class;
            $dataKey = $meta->isCampaign() ? 'campaign_id' : 'game_id';
            app(NotificationService::class)->markReadByType($user, $invitationType, $entity->id, $dataKey);
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_on_accept_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine the overflow status for a full entity.
     *
     * @return array{status: string, timestamp_column: string}
     */
    private function resolveOverflowStatus(Game|Campaign $entity): array
    {
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
     */
    private function addEmailInviteeToOverflow(
        Game|Campaign $entity,
        EntityMeta $meta,
        string $normalizedEmail,
        User $inviter,
        ?string $existingUserId = null,
    ): void {
        $overflow = $this->resolveOverflowStatus($entity);

        $data = [
            $meta->foreignKey => $entity->id,
            'user_id' => $existingUserId,
            'invitee_email' => $existingUserId ? null : $normalizedEmail,
            'role' => ParticipantRole::Invited->value,
            'status' => $overflow['status'],
            'join_source' => JoinSource::EmailInvite,
            $overflow['timestamp_column'] => now(),
        ];

        $meta->participantClass::create($data);

        $logContext = [
            'entity_type' => $meta->type,
            $meta->foreignKey => $entity->id,
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'invited_by' => $inviter->id,
            'overflow_status' => $overflow['status'],
        ];
        if ($existingUserId) {
            $logContext['invited_user_id'] = $existingUserId;
        }
        Log::info($meta->type.' email invite added to '.$overflow['status'], $logContext);

        // Send notification/email so the person knows they've been invited
        if ($existingUserId) {
            $existingUser = User::find($existingUserId);
            if ($existingUser) {
                $this->sendInvitationNotification($entity, $existingUser, $inviter);
            }
        } else {
            try {
                if (SuppressedInviteEmail::isSuppressed($normalizedEmail)) {
                    Log::info('invite.email.suppressed_overflow', [
                        'entity_type' => $meta->type,
                        $meta->foreignKey => $entity->id,
                        'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                        'invited_by' => $inviter->id,
                    ]);
                } else {
                    $mailable = new EntityInvitationEmail(
                        entityType: strtolower($meta->type),
                        entityName: $entity->name,
                        entityDateTime: $entity->date_time ?? null,
                        entityLocation: $entity->linkedLocation->address ?? null,
                        inviterName: $inviter->name,
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
                    'entity_type' => $meta->type,
                    $meta->foreignKey => $entity->id,
                    'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Move an accepted invitee to waitlist/bench when entity is full.
     */
    private function addAcceptedInviteeToOverflow(
        GameParticipant|CampaignParticipant $participant,
        Game|Campaign $entity,
        EntityMeta $meta,
    ): void {
        $overflow = $this->resolveOverflowStatus($entity);

        $participant->update([
            'status' => $overflow['status'],
            $overflow['timestamp_column'] => now(),
        ]);

        Log::info($meta->type.' invitation accepted but entity full — moved to '.$overflow['status'], [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->user_id,
            'overflow_status' => $overflow['status'],
        ]);
    }

    /**
     * Get the appropriate flash message key for overflow (waitlist or bench).
     */
    private function overflowFlashResult(Game|Campaign $entity): ParticipantResult
    {
        $overflow = $this->resolveOverflowStatus($entity);
        $messageKey = $overflow['status'] === ParticipantStatus::Waitlisted->value
            ? 'people.flash_email_invite_waitlisted'
            : 'people.flash_email_invite_benched';

        return ParticipantResult::ok($messageKey);
    }
}
