<?php

namespace App\Services;

use App\Contracts\Participant;
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
use App\Notifications\EntityInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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

            if ($targetUser->is($inviter)) {
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

            if ($entity->participants()->whereBelongsTo($targetUser)->exists()) {
                $skippedCount++;

                continue;
            }

            try {
                $entity->participants()->create([
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
        // Collapse Gmail-family variants (dots, "+suffix", @googlemail.com) to
        // one canonical form so dedup, storage, and registration-time matching
        // all agree — otherwise an invite to "a.b@gmail.com" would neither dedup
        // against nor be claimable by a Google signup that returns "ab@gmail.com".
        $normalizedEmail = EmailCanonicalizer::canonical($normalizedEmail);

        // Self-invite check (compare on canonical form for the same reason).
        if ($normalizedEmail === EmailCanonicalizer::canonical($inviter->email)) {
            return ParticipantResult::fail('people.error_cannot_invite_self');
        }

        // Rate limit: 10 email invites per user per entity per hour
        $rateLimitKey = 'email-invite:'.$inviter->id.':'.$entity->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ParticipantResult::fail('people.error_too_many_invite_attempts');
        }
        RateLimiter::hit($rateLimitKey, 3600);

        // Check if the email belongs to an existing user. users.email is not
        // stored canonically (registrants keep whatever form they signed up
        // with), so look up both the canonical and raw-lowercased forms to
        // catch a Gmail account regardless of which variant was registered.
        $existingUser = User::whereIn('email', array_values(array_filter(
            array_unique([$normalizedEmail, strtolower(trim($email))])
        )))->first();

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
        if ($entity->participants()->whereBelongsTo($existingUser)->exists()) {
            return ParticipantResult::fail('people.error_user_already_participant');
        }

        if ($this->isAtCapacity($entity)) {
            app(OverflowRouter::class)->placeEmailInvitee($entity, $meta, $normalizedEmail, $inviter, $existingUser->id);

            return app(OverflowRouter::class)->flashResult($entity);
        }

        $entity->participants()->create([
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

        // Duplicate check: existing invite to this email (Pending, Waitlisted,
        // or Benched — overflow invites carry the latter two statuses).
        $duplicateQuery = $meta->participantClass::where($meta->foreignKey, $entity->id)
            ->where('role', ParticipantRole::Invited->value)
            ->whereIn('status', [
                ParticipantStatus::Pending->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
            ]);

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
            app(OverflowRouter::class)->placeEmailInvitee($entity, $meta, $normalizedEmail, $inviter);

            return app(OverflowRouter::class)->flashResult($entity);
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
            $entity->participants()->create([
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
            $entity->participants()->create([
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
     * so they are counted naturally. Delegates to the canonical implementation
     * on the entity ({@see HasCapacity::approvedParticipantCount()}).
     */
    public function getApprovedParticipantCount(Game|Campaign $entity): int
    {
        return $entity->approvedParticipantCount();
    }

    /**
     * Check if the entity has reached max_players capacity.
     *
     * Delegates to the canonical implementation on the entity
     * ({@see HasCapacity::isAtCapacity()}) so every "is it full?" decision —
     * read path, write path, and tests — resolves through one predicate.
     * Returns false when max_players is null or 0 (unlimited capacity).
     */
    public function isAtCapacity(Game|Campaign $entity): bool
    {
        return $entity->isAtCapacity();
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
}
