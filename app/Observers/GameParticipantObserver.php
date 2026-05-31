<?php

namespace App\Observers;

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

    public function created(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week']);
        $this->cache->invalidateActionCenterForParticipantChange(
            $participant->user_id,
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
            $this->cache->invalidateForUser((string) $participant->user_id, ['week']);
            $this->cache->invalidateActionCenterForParticipantChange(
                $participant->user_id,
                $participant->game_id,
            );
        }

        // Attendance reporting affects the unreported-attendance item
        if ($participant->wasChanged('attendance_status')) {
            $this->cache->invalidateActionCenterForAttendance($participant->user_id);

            // Mode may transition (newcomer → established) when attendance is recorded
            $user = $participant->user;
            if ($user) {
                $this->modeService->invalidateForUser($user);
            }
        }
    }

    public function deleted(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week']);
        $this->cache->invalidateActionCenterForParticipantChange(
            $participant->user_id,
            $participant->game_id,
        );

        Log::debug('dashboard.invalidation.player_left', [
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
        ]);
    }
}
