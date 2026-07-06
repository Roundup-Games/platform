<?php

namespace App\Observers;

use App\Enums\ParticipantStatus;
use App\Models\GameParticipant;
use App\Services\DashboardCacheService;
use App\Services\DashboardModeService;
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
    }
}
