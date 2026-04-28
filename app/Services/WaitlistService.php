<?php

namespace App\Services;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\WaitlistPromoted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistService
{
    /**
     * Urgency-scaled confirmation windows (hours).
     *
     * Time until game  →  hours to confirm
     *   > 7 days           24
     *   2–7 days           12
     *   0–2 days            4
     *   past / today        1
     */
    private const CONFIRMATION_WINDOWS = [
        'far'      => 24,   // > 7 days
        'medium'   => 12,   // 2–7 days
        'near'     => 4,    // 0–2 days
        'imminent' => 1,    // today or past
    ];

    /**
     * Add a user to the waitlist for a standalone game.
     *
     * @throws \LogicException if the game is not standalone, not full, or user is already a participant
     */
    public function addToWaitlist(Game $game, User $user): GameParticipant
    {
        if ($game->campaign_id !== null) {
            throw new \LogicException('Waitlist is only available for standalone games.');
        }

        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < $game->max_players) {
            throw new \LogicException('Cannot add to waitlist: game is not full.');
        }

        $existing = $game->participants()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            throw new \LogicException('User is already a participant of this game.');
        }

        $participant = $game->participants()->create([
            'user_id'      => $user->id,
            'role'         => 'player',
            'status'       => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Log::info('waitlist.added', [
            'game_id'      => $game->id,
            'user_id'      => $user->id,
            'participant_id' => $participant->id,
            'queue_position' => $this->getWaitlistPosition($participant),
        ]);

        return $participant;
    }

    /**
     * Promote the next waitlisted participant to pending-confirmation status.
     *
     * Uses lockForUpdate on the game row to serialize concurrent promotions.
     *
     * @return GameParticipant|null The promoted participant, or null if waitlist is empty
     */
    public function promoteNext(Game $game): ?GameParticipant
    {
        return DB::transaction(function () use ($game) {
            // Lock the game row to serialize concurrent cancel → promote chains
            $lockedGame = Game::lockForUpdate()->findOrFail($game->id);

            $next = $lockedGame->participants()
                ->where('status', ParticipantStatus::Waitlisted->value)
                ->orderBy('waitlisted_at', 'asc')
                ->first();

            if ($next === null) {
                Log::debug('waitlist.promote_next_empty', [
                    'game_id' => $lockedGame->id,
                ]);

                return null;
            }

            $confirmationHours = $this->computeConfirmationWindow($lockedGame);
            $expiresAt = now()->addHours($confirmationHours);

            $next->update([
                'status'                 => ParticipantStatus::Pending->value,
                'confirmation_expires_at' => $expiresAt,
            ]);

            Log::info('waitlist.promoted', [
                'game_id'               => $lockedGame->id,
                'participant_id'        => $next->id,
                'user_id'               => $next->user_id,
                'confirmation_hours'    => $confirmationHours,
                'confirmation_expires_at' => $expiresAt->toIso8601String(),
            ]);

            // Dispatch notification via NotificationService
            $this->notifyPromotion($next, $lockedGame, $expiresAt);

            return $next->fresh();
        });
    }

    /**
     * Confirm a promotion — the participant accepts the spot.
     *
     * @throws \LogicException if the confirmation window has expired
     */
    public function confirmPromotion(GameParticipant $participant): void
    {
        if ($participant->confirmation_expires_at !== null && now()->isAfter($participant->confirmation_expires_at)) {
            Log::warning('waitlist.confirm_expired', [
                'game_id'        => $participant->game_id,
                'participant_id' => $participant->id,
                'user_id'        => $participant->user_id,
                'expired_at'     => $participant->confirmation_expires_at->toIso8601String(),
            ]);

            throw new \LogicException('Confirmation window has expired.');
        }

        $participant->update([
            'status'                 => ParticipantStatus::Approved->value,
            'confirmation_expires_at' => null,
        ]);

        Log::info('waitlist.confirmed', [
            'game_id'        => $participant->game_id,
            'participant_id' => $participant->id,
            'user_id'        => $participant->user_id,
        ]);
    }

    /**
     * Decline a promotion — the participant rejects the spot.
     * Automatically promotes the next waitlisted player.
     */
    public function declinePromotion(GameParticipant $participant): void
    {
        Log::info('waitlist.declined', [
            'game_id'        => $participant->game_id,
            'participant_id' => $participant->id,
            'user_id'        => $participant->user_id,
        ]);

        $participant->update([
            'status'                 => ParticipantStatus::Rejected->value,
            'confirmation_expires_at' => null,
        ]);

        // Promote the next in line
        $game = $participant->game;
        $this->promoteNext($game);
    }

    /**
     * Handle an expired confirmation — move participant to back of queue
     * and promote the next waitlisted player.
     */
    public function handleExpiredConfirmation(GameParticipant $participant): void
    {
        DB::transaction(function () use ($participant) {
            $gameId = $participant->game_id;

            Log::warning('waitlist.confirmation_expired', [
                'game_id'        => $gameId,
                'participant_id' => $participant->id,
                'user_id'        => $participant->user_id,
                'expired_at'     => $participant->confirmation_expires_at?->toIso8601String(),
            ]);

            // Move to back of queue first, then promote next
            $participant->update([
                'status'                 => ParticipantStatus::Waitlisted->value,
                'waitlisted_at'          => now(),
                'confirmation_expires_at' => null,
            ]);

            // Promote the next in line using game ID to avoid stale model
            $this->promoteNextFromGameId($gameId, $participant->id);
        });
    }

    /**
     * Manually promote a waitlisted participant (host override, skips FIFO).
     */
    public function manuallyPromote(GameParticipant $participant): void
    {
        Log::info('waitlist.manually_promoted', [
            'game_id'        => $participant->game_id,
            'participant_id' => $participant->id,
            'user_id'        => $participant->user_id,
        ]);

        $participant->update([
            'status'                 => ParticipantStatus::Approved->value,
            'confirmation_expires_at' => null,
            'waitlisted_at'          => null,
        ]);
    }

    /**
     * Get the 1-based position of a waitlisted participant in the queue.
     */
    public function getWaitlistPosition(GameParticipant $participant): int
    {
        return $participant->game->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->where('waitlisted_at', '<', $participant->waitlisted_at)
            ->count() + 1;
    }

    /**
     * Promote as many waitlisted players as there are open slots
     * after a cancellation. This is the entry point called by
     * cancellation handlers.
     */
    public function promoteAllOnCancel(Game $game): void
    {
        $openSlots = $game->max_players - $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        for ($i = 0; $i < $openSlots; $i++) {
            $promoted = $this->promoteNext($game);
            if ($promoted === null) {
                break;
            }
        }

        // Check below-min-player warning
        $this->checkBelowMinPlayers($game);
    }

    /**
     * Handle game cancellation — reject all waitlisted/benched participants.
     */
    public function handleGameCancellation(Game $game): void
    {
        $affected = $game->participants()
            ->whereIn('status', [ParticipantStatus::Waitlisted->value, ParticipantStatus::Benched->value])
            ->get();

        foreach ($affected as $participant) {
            $participant->update(['status' => ParticipantStatus::Rejected->value]);
        }

        Log::info('waitlist.game_cancelled', [
            'game_id'         => $game->id,
            'affected_count'  => $affected->count(),
            'affected_status' => 'rejected',
        ]);
    }

    // ── Internal helpers ────────────────────────────────

    /**
     * Promote next waitlisted player by game ID.
     * Used within transactions where we already have the game locked
     * and want to avoid re-locking via the Game model.
     */
    private function promoteNextFromGameId(string $gameId, ?string $excludeParticipantId = null): ?GameParticipant
    {
        $query = GameParticipant::where('game_id', $gameId)
            ->where('status', ParticipantStatus::Waitlisted->value);

        if ($excludeParticipantId !== null) {
            $query->where('id', '!=', $excludeParticipantId);
        }

        $next = $query->orderBy('waitlisted_at', 'asc')->first();

        if ($next === null) {
            return null;
        }

        $game = Game::find($gameId);
        $confirmationHours = $this->computeConfirmationWindow($game);
        $expiresAt = now()->addHours($confirmationHours);

        $next->update([
            'status'                 => ParticipantStatus::Pending->value,
            'confirmation_expires_at' => $expiresAt,
        ]);

        Log::info('waitlist.promoted', [
            'game_id'               => $gameId,
            'participant_id'        => $next->id,
            'user_id'               => $next->user_id,
            'confirmation_hours'    => $confirmationHours,
            'confirmation_expires_at' => $expiresAt->toIso8601String(),
        ]);

        $this->notifyPromotion($next, $game, $expiresAt);

        return $next->fresh();
    }

    /**
     * Compute urgency-scaled confirmation window in hours based on time until game.
     */
    private function computeConfirmationWindow(Game $game): int
    {
        $dateTime = $game->date_time;

        if ($dateTime === null) {
            return self::CONFIRMATION_WINDOWS['far'];
        }

        $hoursUntil = now()->diffInHours($dateTime, false);

        return match (true) {
            $hoursUntil <= 0   => self::CONFIRMATION_WINDOWS['imminent'],
            $hoursUntil <= 48  => self::CONFIRMATION_WINDOWS['near'],
            $hoursUntil <= 168 => self::CONFIRMATION_WINDOWS['medium'],
            default            => self::CONFIRMATION_WINDOWS['far'],
        };
    }

    /**
     * Dispatch the WaitlistPromoted notification through NotificationService.
     */
    private function notifyPromotion(GameParticipant $participant, Game $game, $expiresAt): void
    {
        try {
            $notificationService = app(NotificationService::class);
            $user = $participant->user;

            $notification = new WaitlistPromoted(
                game: $game,
                confirmationDeadline: $expiresAt->isoFormat('LLL'),
            );

            $notificationService->send($user, $notification, \App\Enums\NotificationCategory::ParticipantJoined);
        } catch (\Throwable $e) {
            Log::error('waitlist.notification_failed', [
                'game_id'        => $game->id,
                'participant_id' => $participant->id,
                'user_id'        => $participant->user_id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a warning if the roster is below min_players after a promotion chain.
     */
    private function checkBelowMinPlayers(Game $game): void
    {
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($game->min_players !== null && $approvedCount < $game->min_players) {
            Log::warning('waitlist.below_min_players', [
                'game_id'         => $game->id,
                'current_roster'  => $approvedCount,
                'min_players'     => $game->min_players,
            ]);
        }
    }
}
