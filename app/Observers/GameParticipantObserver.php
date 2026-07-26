<?php

namespace App\Observers;

use App\Enums\ParticipantStatus;
use App\Jobs\RefreshDiscordCard;
use App\Models\GameParticipant;
use App\Services\DashboardCacheService;
use App\Services\DashboardModeService;
use App\Support\HostAutoFollow;
use Illuminate\Support\Facades\Log;

class GameParticipantObserver
{
    public function __construct(
        private DashboardCacheService $cache,
        private DashboardModeService $modeService,
    ) {}

    /**
     * Stamp approved_at whenever a participant is in the Approved status without
     * one — the load-bearing invariant for CapacityService's LIFO demotion
     * ordering (`ORDER BY approved_at IS NULL ASC, approved_at DESC`).
     *
     * Pre-write hook (fires for both create and update) so the stamp lands in
     * the same operation. Existing callsites already set approved_at explicitly;
     * this is the defense-in-depth backstop so a future Approved-transition
     * site that forgets cannot silently shield brand-new players from demotion
     * (the exact bug that shipped once before the b5794cd5 review round). Only
     * stamps when missing — explicit values (OwnerParticipant's now(), the
     * legacy backfill via raw SQL which bypasses the model entirely) are
     * respected.
     */
    public function saving(GameParticipant $participant): void
    {
        if ($participant->status === ParticipantStatus::Approved && $participant->approved_at === null) {
            $participant->approved_at = now();
        }
    }

    public function created(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week', 'progress_tracker']);
        $this->cache->invalidateActionCenterForParticipantChange(
            (string) $participant->user_id,
            $participant->game_id,
        );

        Log::debug('dashboard.invalidation.player_joined', [
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
        ]);

        if (config('community.auto_follow_on_join', true)) {
            HostAutoFollow::followHost(
                $participant->user,
                $participant->game?->owner,
                'game',
                $participant->game_id,
            );
        }

        // M057/S04/T02: a new participant (a join) changes the game's card
        // roster state — dispatch the debounced card refresh. Gated behind
        // publishing_enabled (MEM918); best-effort, never blocks the write.
        $this->maybeDispatchCardRefresh($participant);
    }

    public function updated(GameParticipant $participant): void
    {
        // Status changes (approved, waitlisted, etc.) affect action center items
        if ($participant->wasChanged('status')) {
            $this->cache->invalidateForUser((string) $participant->user_id, ['week', 'progress_tracker']);
            $this->cache->invalidateActionCenterForParticipantChange(
                (string) $participant->user_id,
                $participant->game_id,
            );

            // M057/S04/T02: a status change (waitlisted->approved,
            // approved->benched) changes the game's card roster state —
            // dispatch the debounced card refresh. Gated behind
            // publishing_enabled (MEM918); best-effort, never blocks the write.
            $this->maybeDispatchCardRefresh($participant);
        }

        // Attendance reporting affects the unreported-attendance item
        if ($participant->wasChanged('attendance_status')) {
            $this->cache->invalidateActionCenterForAttendance((string) $participant->user_id);

            // Mode may transition (newcomer → established) when attendance is recorded
            $user = $participant->user;
            if ($user) {
                $this->modeService->invalidateForUser($user);
                // Clear progress tracker — step 4 (Attend Session) may have changed
                $this->cache->invalidateForUser((string) $user->id, ['progress_tracker']);
            }
        }
    }

    public function deleted(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week', 'progress_tracker']);
        $this->cache->invalidateActionCenterForParticipantChange(
            (string) $participant->user_id,
            $participant->game_id,
        );

        Log::debug('dashboard.invalidation.player_left', [
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
        ]);

        // M057/S04/T02: a participant leaving (a drop) changes the game's card
        // roster state — dispatch the debounced card refresh. Gated behind
        // publishing_enabled (MEM918); best-effort, never blocks the write.
        $this->maybeDispatchCardRefresh($participant);
    }

    /**
     * M057/S04/T02: dispatch the debounced RefreshDiscordCard job when roster
     * churn changes a game's card roster state (a join in created(), a status
     * change like waitlisted->approved / approved->benched in updated(), or a
     * drop in deleted()). Roster churn never re-saves the Game, so
     * GameObserver::saved() (which dispatches PublishGameToDiscord on material
     * change) never fires for churn — this hook closes that gap. The job is
     * ShouldBeUnique keyed on gameId, so rapid churn within the debounce window
     * coalesces to a single edit-in-place PATCH per game.
     *
     * Gated behind the publishing_enabled master switch (MEM918) so the
     * existing sync-queue test suite is unaffected. Best-effort: a dispatch
     * infrastructure failure is swallowed and logged so it never blocks the
     * participant write (mirrors GameObserver::maybeDispatchDiscordPublish).
     */
    private function maybeDispatchCardRefresh(GameParticipant $participant): void
    {
        if (! config('services.discord.publishing_enabled', false)) {
            return;
        }

        try {
            RefreshDiscordCard::dispatch((string) $participant->game_id);

            Log::info('discord_publisher.card_refresh_dispatched', [
                'game_id' => $participant->game_id,
            ]);
        } catch (\Throwable $e) {
            // Never block the participant write on dispatch infrastructure.
            Log::warning('discord_publisher.card_refresh_dispatch_failed', [
                'game_id' => $participant->game_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
