<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Dto\EntityMeta;
use App\Dto\InviteBatchResult;
use App\Dto\ParticipantResult;
use App\Dto\RosterReinviteResult;
use App\Enums\GameStatus;
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
use App\Notifications\RosterReinviteInvitation;
use App\Support\Edit\FieldTiers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

class ParticipantService
{
    public function __construct(
        private readonly OverflowRouter $overflowRouter,
        private readonly NotificationService $notificationService,
    ) {}

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
            $userId = $userId;
            $targetUser = User::find($userId);

            if ($targetUser === null) {
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

    // ── Roster re-invite ───────────────────────────────

    /**
     * Re-invite the roster of the cancelled game a replacement stands in for (D320, D321).
     *
     * THE LINK IS THE AUTHORITY. Nobody hands this method a user id: the invitees are the
     * APPROVED non-owner participants of $replacement->recreates, read here. A participant the
     * host removed before cancelling carries status `removed` and is never invited. The host
     * is the predecessor's owner (enforced below), so the inviter is never a candidate either.
     *
     * Why this bypasses the join intake (ParticipantLifecycle::createOrReactivate): every
     * invite path does. inviteFriends(), inviteByEmail() and this method create Pending/Invited
     * rows directly, and a row enters ParticipantLifecycle when the invitee accepts
     * (acceptInvitation()). Nothing is carried over — each former participant opts in again.
     *
     * No friendship check, on purpose. inviteFriends()' mutual-follow rule (R017) protects
     * people from invitations by strangers; someone who already accepted this host's game is
     * no stranger to them, and a roster is rarely a mutual-follow set. That difference is why
     * this is a separate entry point rather than a flag on inviteFriends().
     *
     * Capacity: a pending invitation reserves no seat — acceptInvitation() is the gate that
     * enforces it — so the cap here keeps a smaller replacement from collecting more pending
     * invitations than it has seats. Seats are counted once, at call time, as every other
     * invite path does; the (game_id, user_id) unique constraint is what makes a lost race
     * harmless.
     *
     * @throws LogicException When the game is not a Scheduled game linked to a cancelled one — a programming
     *                        error, never a user error: the banner only offers the action where it applies.
     * @throws AuthorizationException When the predecessor is not cancelled, the inviter is not its host, or may
     *                                not update the replacement.
     */
    public function inviteFormerRoster(Game $replacement, User $inviter): RosterReinviteResult
    {
        // loadMissing: a strict-mode model (dev, tests) must never lazy-load inside a service.
        $replacement->loadMissing('recreates');

        $predecessor = $replacement->recreates;

        if ($predecessor === null || $replacement->status !== GameStatus::Scheduled) {
            throw new LogicException(sprintf(
                'Game [%s] cannot re-invite a roster: it is not a Scheduled game linked to a cancelled predecessor.',
                $replacement->id,
            ));
        }

        if ($predecessor->status !== GameStatus::Canceled
            || $predecessor->owner_id !== $inviter->id
            || ! $inviter->can('update', $replacement)) {
            throw new AuthorizationException('Only the host of the cancelled game may re-invite its roster.');
        }

        $changedFields = self::changedAgreementFields($predecessor, $replacement);
        $remaining = $this->remainingSeats($replacement);

        $invited = 0;
        $skippedCapacity = 0;
        $skippedAlready = 0;
        $skippedMissing = 0;

        foreach ($this->formerRoster($predecessor)->with('user')->get() as $former) {
            $targetUser = $former->user;

            if ($targetUser === null) {
                $skippedMissing++;
                Log::warning('game roster re-invite skipped: user not found', [
                    'game_id' => $replacement->id,
                    'predecessor_id' => $predecessor->id,
                    'participant_id' => $former->id,
                ]);

                continue;
            }

            if ($replacement->participants()->whereBelongsTo($targetUser)->exists()) {
                $skippedAlready++;

                continue;
            }

            if ($invited >= $remaining) {
                $skippedCapacity++;

                continue;
            }

            try {
                // One savepoint per insert: absorbing the violation caught below must
                // never poison a transaction a caller wrapped around this call.
                DB::transaction(fn () => $replacement->participants()->create([
                    'user_id' => $targetUser->id,
                    'role' => ParticipantRole::Invited->value,
                    'status' => ParticipantStatus::Pending->value,
                    'join_source' => JoinSource::RosterReinvite,
                ]));
            } catch (QueryException $e) {
                // Only the race is absorbed: a concurrent invitation won the
                // (game_id, user_id) unique constraint, so the person is already in.
                // Anything else — a constraint the schema does not know, a vanished
                // account — is a real failure and must surface, not count as a skip.
                if (! self::isUniqueViolation($e)) {
                    throw $e;
                }

                $skippedAlready++;
                Log::info('game roster re-invite skipped: duplicate caught by unique constraint', [
                    'game_id' => $replacement->id,
                    'predecessor_id' => $predecessor->id,
                    'invited_user_id' => $targetUser->id,
                    'invited_by' => $inviter->id,
                ]);

                continue;
            }

            Log::info('game participant reinvited', [
                'game_id' => $replacement->id,
                'predecessor_id' => $predecessor->id,
                'invited_user_id' => $targetUser->id,
                'invited_by' => $inviter->id,
            ]);

            $this->sendRosterReinviteNotification($replacement, $targetUser, $inviter, $predecessor->name, $changedFields);

            $invited++;
        }

        $result = new RosterReinviteResult($invited, $skippedCapacity, $skippedAlready, $skippedMissing, $changedFields);

        // One structured line per call, counts only — the slice's observability contract.
        Log::info('reinvite.roster', [
            'game_id' => $replacement->id,
            'predecessor_id' => $predecessor->id,
            'invited' => $result->invited,
            'skipped_capacity' => $result->skippedCapacity,
            'skipped_already' => $result->skippedAlready,
            'skipped_missing' => $result->skippedMissing,
            'changed_fields' => $changedFields,
        ]);

        // Counts only; the helper is a no-op without consent and never throws.
        app(PostHogAnalytics::class)->capture($inviter, 'game.roster_reinvited', [
            'game_id' => $replacement->id,
            'invited' => $result->invited,
            'skipped_capacity' => $result->skippedCapacity,
        ]);

        return $result;
    }

    /**
     * How many former players "Invite the previous roster" can invite right now: those not yet
     * part of the replacement, capped by its free seats. 0 whenever there is nothing to offer —
     * an unlinked game, a full one, or a roster that has already been invited — which is what
     * hides the banner. inviteFormerRoster() decides what really happens; this only counts.
     */
    public function countReinvitableFormerRoster(Game $replacement): int
    {
        $replacement->loadMissing('recreates');

        $predecessor = $replacement->recreates;

        if ($predecessor === null || $predecessor->status !== GameStatus::Canceled) {
            return 0;
        }

        $notYetInvited = $this->formerRoster($predecessor)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('game_participants as current')
                ->whereColumn('current.user_id', 'game_participants.user_id')
                ->where('current.game_id', $replacement->id))
            ->count();

        return min($notYetInvited, $this->remainingSeats($replacement));
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
            return ParticipantResult::fail('common.error_you_cannot_invite_yourself');
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
            array_unique([$normalizedEmail, strtolower(trim($email))]),
            fn (string $email): bool => $email !== ''
        )))->first();

        if ($existingUser !== null) {
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
            $this->overflowRouter->placeEmailInvitee($entity, $meta, $normalizedEmail, $inviter, $existingUser->id);

            return $this->overflowRouter->flashResult($entity);
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

        if ($duplicateQuery->first() !== null) {
            return ParticipantResult::fail('people.error_email_invite_already_sent');
        }

        if ($this->isAtCapacity($entity)) {
            $this->overflowRouter->placeEmailInvitee($entity, $meta, $normalizedEmail, $inviter);

            return $this->overflowRouter->flashResult($entity);
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
                signupUrl: route('login', ['locale' => app()->getLocale()]),
                optoutUrl: route('invite.optout.show', [
                    'locale' => app()->getLocale(),
                    'emailHash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                ]),
                entityLanguage: $entity->language ?? null,
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

            $this->notificationService->send($target, $notificationClass, $category);
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
     * Send the roster re-invitation down the ordinary GameInvitation path (D320).
     *
     * @param  list<string>  $changedFields
     */
    private function sendRosterReinviteNotification(
        Game $replacement,
        User $target,
        User $inviter,
        string $replacedGameName,
        array $changedFields,
    ): void {
        try {
            $this->notificationService->send(
                $target,
                new RosterReinviteInvitation($replacement, $inviter, $replacedGameName, $changedFields),
                NotificationCategory::GameInvitation,
            );
        } catch (\Throwable $e) {
            Log::error('notification.roster_reinvite_dispatch_failed', [
                'game_id' => $replacement->id,
                'target_user_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The eligible set of a roster re-invite: the cancelled game's approved participants who
     * are not its owner (the inviter) and have an account — an invitee-by-email row has a
     * NULL user_id, which `<>` rejects too. Earliest approval first: the fairest order when
     * seats run out.
     *
     * @return HasMany<GameParticipant, Game>
     */
    private function formerRoster(Game $predecessor): HasMany
    {
        return $predecessor->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '<>', $predecessor->owner_id)
            ->orderBy('approved_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Seats still free on the game — PHP_INT_MAX when max_players is 0 or null, which
     * HasCapacity reads as unlimited (a naive `0 - approved` would invite nobody).
     */
    private function remainingSeats(Game $game): int
    {
        if ($game->max_players === null || $game->max_players === 0) {
            return PHP_INT_MAX;
        }

        return max(0, $game->max_players - $this->getApprovedParticipantCount($game));
    }

    /**
     * The Tier-1 fields that really differ between a cancelled game and its replacement —
     * what every roster re-invitation states (D320). Tier 1 is what a participant agreed
     * to (D313); max_players joins them whenever its tier changes (a decrease locks, an
     * increase notifies).
     *
     * Values decide, never names. Both rows are re-read so the comparison sees STORED
     * values rather than whatever a caller's in-memory copy carries, and two spellings of
     * one value — null and 0 for a free price, or for unlimited capacity — are the same.
     * The offered game system is a pivot, so it is compared by id.
     *
     * @return list<string> Machine column names, in FieldTiers::GAME_TIERS order, max_players last.
     */
    private static function changedAgreementFields(Game $predecessor, Game $replacement): array
    {
        $before = $predecessor->fresh();
        $after = $replacement->fresh();

        if ($before === null || $after === null) {
            return [];
        }

        $changed = [];

        foreach (array_keys(FieldTiers::GAME_TIERS) as $field) {
            // game_system_id is the same pivot as game_systems, compared once with it.
            if ($field === 'game_system_id' || FieldTiers::tierOf(Game::class, $field) !== FieldTiers::TIER_LOCKED) {
                continue;
            }

            if ($field === 'game_systems') {
                $systemChange = self::gameSystemChange($before, $after);

                if ($systemChange !== null) {
                    $changed[] = $systemChange;
                }

                continue;
            }

            if (! self::sameStoredValue($field, $before->getRawOriginal($field), $after->getRawOriginal($field))) {
                $changed[] = $field;
            }
        }

        $beforeMaxPlayers = (int) self::asNumber($before->getRawOriginal('max_players'));
        $afterMaxPlayers = (int) self::asNumber($after->getRawOriginal('max_players'));

        if (FieldTiers::maxPlayersTier($beforeMaxPlayers, $afterMaxPlayers) !== FieldTiers::TIER_FREE) {
            $changed[] = 'max_players';
        }

        return $changed;
    }

    /**
     * The game-system key whose value changed between two games, or null when both offer
     * the same systems: `game_system_id` when the representative (first, by slug) system
     * differs, `game_systems` when only a Gathering's wider set does.
     */
    private static function gameSystemChange(Game $before, Game $after): ?string
    {
        $beforeSystems = $before->gameSystems()->pluck('game_systems.id')->all();
        $afterSystems = $after->gameSystems()->pluck('game_systems.id')->all();

        if (($beforeSystems[0] ?? null) !== ($afterSystems[0] ?? null)) {
            return 'game_system_id';
        }

        $sorted = fn (array $ids): array => collect($ids)->map(fn ($id): string => (string) $id)->sort()->values()->all();

        return $sorted($beforeSystems) !== $sorted($afterSystems) ? 'game_systems' : null;
    }

    /**
     * Whether two stored Tier-1 values are one value. Numeric columns compare as numbers
     * with null read as 0; everything else (enum backing values, timestamps, booleans,
     * the language) compares as stored.
     */
    private static function sameStoredValue(string $field, mixed $before, mixed $after): bool
    {
        if (in_array($field, ['expected_duration', 'price', 'min_players'], true)) {
            return self::asNumber($before) === self::asNumber($after);
        }

        return self::asText($before) === self::asText($after);
    }

    /**
     * A stored value as a number: null — and nothing else is ever stored in these
     * columns — counts as 0.
     */
    private static function asNumber(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** A stored scalar as text, null included: two nulls read as the same value. */
    private static function asText(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Whether a failed insert hit a unique index (Postgres SQLSTATE 23505).
     */
    private static function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            || $exception->getCode() === '23505';
    }
}
