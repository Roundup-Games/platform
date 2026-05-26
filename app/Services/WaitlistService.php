<?php

namespace App\Services;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Jobs\HandleExpiredConfirmation;
use App\Notifications\ConfirmationExpired;
use App\Notifications\WaitlistPromoted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistService
{
    /**
     * Maximum number of times a player's confirmation can expire
     * before they are permanently rejected from this entity's waitlist.
     */
    public const MAX_CONFIRMATION_EXPIRATIONS = 2;

    /**
     * Urgency-scaled confirmation windows (hours).
     *
     * Time until game  →  hours to confirm
     *   > 48h              12
     *   24–48h              6
     *   4–24h               2
     *   < 4h               0.5
     */
    private const CONFIRMATION_WINDOWS = [
        'far'      => 12,   // > 48h before game
        'medium'   => 6,    // 24-48h before game
        'near'     => 2,    // 4-24h before game
        'imminent' => 0.5,  // < 4h before game (30 minutes)
    ];

    // ── Entity metadata helper ─────────────────────────

    /**
     * Resolve metadata for a Game|Campaign or CampaignParticipant|GameParticipant.
     *
     * Centralizes the repeated `instanceof` checks used throughout this service
     * for logging, locking, and querying. Accepts either an entity or a participant
     * and returns a consistent metadata array.
     *
     * @param  Campaign|Game|CampaignParticipant|GameParticipant  $subject
     * @return array{type: string, foreignKey: string, class: class-string<Campaign|Game>, participantClass: class-string<CampaignParticipant|GameParticipant>, isCampaign: bool}
     */
    private function entityMeta(Campaign|Game|CampaignParticipant|GameParticipant $subject): array
    {
        $isCampaign = $subject instanceof Campaign
            || $subject instanceof CampaignParticipant;

        return $this->entityMetaFromClass(
            $isCampaign ? Campaign::class : Game::class
        );
    }

    // ── Public API (supports both Game and Campaign) ───

    /**
     * Add a user to the waitlist for a game or campaign (when bench_mode=false).
     *
     * @throws \LogicException if entity uses bench mode, is not full, or user is already a participant
     */
    public function addToWaitlist(Campaign|Game $entity, User $user): CampaignParticipant|GameParticipant
    {
        $meta = $this->entityMeta($entity);

        if ($entity->isBenchMode()) {
            throw new \LogicException("Waitlist is not available for this {$meta['type']} (bench mode is enabled).");
        }

        $approvedCount = app(ParticipantService::class)->getApprovedPlayerCount($entity);

        if ($entity->max_players === null || $approvedCount < $entity->max_players) {
            throw new \LogicException('Cannot add to waitlist: entity is not full.');
        }

        $existing = $entity->participants()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            throw new \LogicException('User is already a participant of this entity.');
        }

        $participant = $entity->participants()->create([
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Log::info('waitlist.added', [
            'entity_type' => $meta['type'],
            $meta['foreignKey'] => $entity->id,
            'user_id' => $user->id,
            'participant_id' => $participant->id,
            'queue_position' => $this->getWaitlistPosition($participant),
        ]);

        return $participant;
    }

    /**
     * Promote the next waitlisted participant to pending-confirmation status.
     *
     * Uses lockForUpdate on the entity row to serialize concurrent promotions.
     *
     * @return CampaignParticipant|GameParticipant|null The promoted participant, or null if waitlist is empty
     */
    public function promoteNext(Campaign|Game $entity): CampaignParticipant|GameParticipant|null
    {
        return DB::transaction(function () use ($entity) {
            $meta = $this->entityMeta($entity);
            $lockedEntity = $meta['class']::lockForUpdate()->findOrFail($entity->id);

            $next = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Waitlisted->value)
                ->orderBy('waitlisted_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                Log::debug('waitlist.promote_next_empty', [
                    'entity_id' => $lockedEntity->id,
                ]);

                return null;
            }

            $confirmationHours = $this->computeConfirmationWindow($lockedEntity);
            $expiresAt = now()->addMinutes((int) round($confirmationHours * 60));

            $next->update([
                'status' => ParticipantStatus::Pending->value,
                'confirmation_expires_at' => $expiresAt,
                'confirmation_attempts' => ($next->confirmation_attempts ?? 0) + 1,
            ]);

            Log::info('waitlist.promoted', [
                'entity_type' => $meta['type'],
                $meta['foreignKey'] => $lockedEntity->id,
                'participant_id' => $next->id,
                'user_id' => $next->user_id,
                'confirmation_hours' => $confirmationHours,
                'confirmation_expires_at' => $expiresAt->toIso8601String(),
            ]);

            $this->notifyPromotion($next, $lockedEntity, $expiresAt);

            HandleExpiredConfirmation::dispatch($next->id, get_class($next))->delay($expiresAt);

            return $next->fresh();
        });
    }

    /**
     * Confirm a promotion — the participant accepts the spot.
     *
     * @throws \LogicException if the confirmation window has expired
     */
    public function confirmPromotion(CampaignParticipant|GameParticipant $participant): void
    {
        $meta = $this->entityMeta($participant);

        if ($participant->confirmation_expires_at !== null && now()->isAfter($participant->confirmation_expires_at)) {
            Log::warning('waitlist.confirm_expired', [
                $meta['foreignKey'] => $participant->{$meta['foreignKey']},
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'expired_at' => $participant->confirmation_expires_at->toIso8601String(),
            ]);

            throw new \LogicException('Confirmation window has expired.');
        }

        $participant->update([
            'status' => ParticipantStatus::Approved->value,
            'confirmation_expires_at' => null,
        ]);

        Log::info('waitlist.confirmed', [
            $meta['foreignKey'] => $participant->{$meta['foreignKey']},
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
        ]);
    }

    /**
     * Decline a promotion — the participant rejects the spot.
     * Automatically promotes the next waitlisted player.
     */
    public function declinePromotion(CampaignParticipant|GameParticipant $participant): void
    {
        $meta = $this->entityMeta($participant);

        Log::info('waitlist.declined', [
            $meta['foreignKey'] => $participant->{$meta['foreignKey']},
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
        ]);

        $participant->update([
            'status' => ParticipantStatus::Rejected->value,
            'confirmation_expires_at' => null,
        ]);

        // Load the entity relationship if not already eager-loaded
        $participant->loadMissing($meta['isCampaign'] ? 'campaign' : 'game');
        $entity = $meta['isCampaign'] ? $participant->campaign : $participant->game;

        $this->promoteNext($entity);
    }

    /**
     * Handle an expired confirmation — move participant to back of queue
     * and promote the next waitlisted player.
     */
    public function handleExpiredConfirmation(CampaignParticipant|GameParticipant $participant): void
    {
        $meta = $this->entityMeta($participant);
        $entityId = $participant->{$meta['foreignKey']};

        DB::transaction(function () use ($participant, $entityId, $meta) {
            // Lock the entity row to serialize concurrent expired-confirmation
            // handlers for the same entity.
            $meta['class']::lockForUpdate()->findOrFail($entityId);

            // Refresh participant within the locked transaction
            $participant->refresh();

            // Guard: if another handler already resolved this participant, bail out
            if ($participant->status->value !== 'pending') {
                return;
            }

            Log::warning('waitlist.confirmation_expired', [
                $meta['foreignKey'] => $entityId,
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'expired_at' => $participant->confirmation_expires_at?->toIso8601String(),
            ]);

            $confirmationAttempts = $participant->confirmation_attempts ?? 0;

            if ($confirmationAttempts >= self::MAX_CONFIRMATION_EXPIRATIONS) {
                // Max expirations exceeded — reject permanently
                $participant->update([
                    'status' => ParticipantStatus::Rejected->value,
                    'confirmation_expires_at' => null,
                ]);

                Log::info('waitlist.rejected_max_expirations', [
                    $meta['foreignKey'] => $entityId,
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'confirmation_attempts' => $confirmationAttempts,
                ]);
            } else {
                // Move to back of queue
                $participant->update([
                    'status' => ParticipantStatus::Waitlisted->value,
                    'waitlisted_at' => now(),
                    'confirmation_expires_at' => null,
                ]);
            }

            // Promote the next in line using entity ID to avoid stale model
            $this->promoteNextFromEntityId($entityId, $meta['class'], $participant->id);
        });

        // Send notification outside the transaction
        $participant->refresh();

        try {
            $notificationService = app(NotificationService::class);
            $user = $participant->user;

            // Ensure entity relationship is loaded for notification routing
            $participant->loadMissing($meta['isCampaign'] ? 'campaign' : 'game');
            $entity = $meta['isCampaign'] ? $participant->campaign : $participant->game;

            if ($participant->status === ParticipantStatus::Rejected) {
                $notificationService->send(
                    $user,
                    new \App\Notifications\WaitlistExpiredRejected($entity, $participant->confirmation_attempts),
                    \App\Enums\NotificationCategory::ConfirmationExpired
                );
            } else {
                $notificationService->send(
                    $user,
                    new ConfirmationExpired($entity),
                    \App\Enums\NotificationCategory::ConfirmationExpired
                );
            }
        } catch (\Throwable $e) {
            Log::error('waitlist.confirmation_expired_notification_failed', [
                'entity_id' => $entityId,
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manually promote a waitlisted participant to approved status.
     *
     * Intentional host override — capacity (max_players) is not enforced.
     * The host explicitly decides to exceed the roster limit. This is by
     * design: the host knows their table better than the software does.
     *
     * Downstream capacity checks (invitation acceptance, discovery counts)
     * must tolerate approved_count > max_players when manual promotions exist.
     */
    public function manuallyPromote(CampaignParticipant|GameParticipant $participant): void
    {
        $meta = $this->entityMeta($participant);

        Log::info('waitlist.manually_promoted', [
            $meta['foreignKey'] => $participant->{$meta['foreignKey']},
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
        ]);

        $participant->update([
            'status' => ParticipantStatus::Approved->value,
            'confirmation_expires_at' => null,
            'waitlisted_at' => null,
        ]);
    }

    /**
     * Get the 1-based position of a waitlisted participant in the queue.
     * Uses (waitlisted_at, id) ordering to break ties when timestamps collide.
     */
    public function getWaitlistPosition(CampaignParticipant|GameParticipant $participant): int
    {
        $meta = $this->entityMeta($participant);
        $entity = $meta['isCampaign'] ? $participant->campaign : $participant->game;

        return $entity->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->where(function ($q) use ($participant) {
                $q->where('waitlisted_at', '<', $participant->waitlisted_at)
                    ->orWhere(function ($q2) use ($participant) {
                        $q2->where('waitlisted_at', '=', $participant->waitlisted_at)
                            ->where('id', '<', $participant->id);
                    });
            })
            ->count() + 1;
    }

    /**
     * Promote as many waitlisted players as there are open slots
     * after a cancellation. This is the entry point called by
     * cancellation handlers.
     */
    public function promoteAllOnCancel(Campaign|Game $entity): void
    {
        // No capacity limit means no waitlist to promote from
        if ($entity->max_players === null) {
            return;
        }

        $approvedCount = app(ParticipantService::class)->getApprovedPlayerCount($entity);
        $openSlots = max(0, $entity->max_players - $approvedCount);

        for ($i = 0; $i < $openSlots; $i++) {
            $promoted = $this->promoteNext($entity);
            if ($promoted === null) {
                break;
            }
        }

        $this->checkBelowMinPlayers($entity);
    }

    /**
     * Handle game cancellation — reject all waitlisted/benched participants.
     */
    public function handleGameCancellation(Game $game): void
    {
        $this->handleEntityCancellation($game);
    }

    /**
     * Handle campaign cancellation — reject all waitlisted/benched participants.
     */
    public function handleCampaignCancellation(Campaign $campaign): void
    {
        $this->handleEntityCancellation($campaign);
    }

    /**
     * Handle entity (game or campaign) cancellation — reject all waitlisted participants.
     *
     * Only handles Waitlisted participants. Benched participants are the responsibility
     * of BenchService::handleEntityCancellation(), which should be called separately.
     * This avoids double-processing of benched participants.
     */
    public function handleEntityCancellation(Campaign|Game $entity): void
    {
        $meta = $this->entityMeta($entity);

        $affected = $entity->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->get();

        foreach ($affected as $participant) {
            $participant->update(['status' => ParticipantStatus::Rejected->value]);
        }

        Log::info('waitlist.entity_cancelled', [
            'entity_type' => $meta['type'],
            $meta['foreignKey'] => $entity->id,
            'affected_count' => $affected->count(),
            'affected_status' => 'rejected',
        ]);
    }

    // ── Internal helpers ────────────────────────────────

    /**
     * Resolve entity metadata from a class string (used when only the class
     * is available, not an entity/participant instance).
     *
     * @param  class-string<Campaign|Game>  $entityClass
     * @return array{type: string, foreignKey: string, class: class-string<Campaign|Game>, participantClass: class-string<CampaignParticipant|GameParticipant>, isCampaign: bool}
     */
    private function entityMetaFromClass(string $entityClass): array
    {
        $isCampaign = $entityClass === Campaign::class;

        return [
            'type' => $isCampaign ? 'campaign' : 'game',
            'foreignKey' => $isCampaign ? 'campaign_id' : 'game_id',
            'class' => $entityClass,
            'participantClass' => $isCampaign ? CampaignParticipant::class : GameParticipant::class,
            'isCampaign' => $isCampaign,
        ];
    }

    /**
     * Promote next waitlisted player by entity ID and class.
     *
     * MUST be called within a DB::transaction() where the caller already holds
     * a lockForUpdate() on the entity row. This method does NOT re-lock the entity,
     * which prevents deadlocks from nested locking. It DOES lockForUpdate() on the
     * participant row to prevent races with concurrent confirmation or leave actions
     * (e.g., user confirming via web while this promotion runs).
     *
     * @see self::handleExpiredConfirmation() — caller that holds the entity lock
     */
    private function promoteNextFromEntityId(string $entityId, string $entityClass, ?string $excludeParticipantId = null): CampaignParticipant|GameParticipant|null
    {
        $meta = $this->entityMetaFromClass($entityClass);

        $query = $meta['participantClass']::where($meta['foreignKey'], $entityId)
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->orderBy('waitlisted_at', 'asc')
            ->orderBy('id', 'asc');

        if ($excludeParticipantId !== null) {
            $query->where('id', '!=', $excludeParticipantId);
        }

        // Lock the participant row to prevent concurrent status changes
        // (e.g., user confirming via web while this promotion runs)
        $next = $query->lockForUpdate()->first();

        if ($next === null) {
            return null;
        }

        $entity = $entityClass::find($entityId);
        $confirmationHours = $this->computeConfirmationWindow($entity);
        $expiresAt = now()->addMinutes((int) round($confirmationHours * 60));

        $next->update([
            'status' => ParticipantStatus::Pending->value,
            'confirmation_expires_at' => $expiresAt,
            'confirmation_attempts' => ($next->confirmation_attempts ?? 0) + 1,
        ]);

        Log::info('waitlist.promoted', [
            'entity_type' => $meta['type'],
            $meta['foreignKey'] => $entityId,
            'participant_id' => $next->id,
            'user_id' => $next->user_id,
            'confirmation_hours' => $confirmationHours,
            'confirmation_expires_at' => $expiresAt->toIso8601String(),
        ]);

        $this->notifyPromotion($next, $entity, $expiresAt);

        HandleExpiredConfirmation::dispatch($next->id, get_class($next))->delay($expiresAt);

        return $next->fresh();
    }

    /**
     * Compute the absolute confirmation deadline (Carbon) for a promoted participant.
     * Returns now() + urgency-scaled window based on time until game start.
     */
    public function computeConfirmationDeadline(Campaign|Game $entity): \Carbon\Carbon
    {
        $hours = $this->computeConfirmationWindow($entity);

        return now()->addMinutes((int) round($hours * 60));
    }

    /**
     * Compute urgency-scaled confirmation window in hours based on time until game.
     * Campaigns don't have date_time, so they default to 'far' (12h).
     */
    private function computeConfirmationWindow(Campaign|Game $entity): float
    {
        // Campaigns don't have a date_time field
        if ($entity instanceof Campaign) {
            return self::CONFIRMATION_WINDOWS['far'];
        }

        $dateTime = $entity->date_time;

        if ($dateTime === null) {
            return self::CONFIRMATION_WINDOWS['far'];
        }

        $hoursUntil = now()->diffInHours($dateTime, false);

        return match (true) {
            $hoursUntil <= 4 => self::CONFIRMATION_WINDOWS['imminent'],
            $hoursUntil <= 24 => self::CONFIRMATION_WINDOWS['near'],
            $hoursUntil <= 48 => self::CONFIRMATION_WINDOWS['medium'],
            default => self::CONFIRMATION_WINDOWS['far'],
        };
    }

    /**
     * Dispatch the WaitlistPromoted notification through NotificationService.
     */
    private function notifyPromotion(CampaignParticipant|GameParticipant $participant, Campaign|Game $entity, $expiresAt): void
    {
        try {
            $notificationService = app(NotificationService::class);
            $user = $participant->user;

            $notification = new WaitlistPromoted(
                entity: $entity,
                confirmationDeadline: $expiresAt->isoFormat('LLL'),
            );

            $notificationService->send($user, $notification, \App\Enums\NotificationCategory::ParticipantJoined);
        } catch (\Throwable $e) {
            Log::error('waitlist.notification_failed', [
                'entity_id' => $entity->id,
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a warning if the roster is below min_players after a promotion chain.
     */
    private function checkBelowMinPlayers(Campaign|Game $entity): void
    {
        $meta = $this->entityMeta($entity);

        $approvedCount = app(ParticipantService::class)->getApprovedPlayerCount($entity);

        if ($entity->min_players !== null && $approvedCount < $entity->min_players) {
            Log::warning('waitlist.below_min_players', [
                $meta['foreignKey'] => $entity->id,
                'current_roster' => $approvedCount,
                'min_players' => $entity->min_players,
            ]);
        }
    }
}
