<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\User;
use App\Notifications\RecapPosted;
use Illuminate\Support\Facades\Log;

/**
 * Announce-only recap effects tail (M071/S03, D219).
 *
 * The one-shot column write path was retired with the Epilogue bulletin;
 * CampaignJournalService::writeRecap is the only recap writer left and
 * injects this service solely for announceRecap() — the shared effects
 * tail (SessionRecapped activity + RecapPosted notifications).
 */
class RecapService
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Announce a written recap: log the SessionRecapped activity and
     * notify the approved participants.
     *
     * Called by CampaignJournalService::writeRecap (M071, D220) so the
     * recap effects — activity feed entry, RecapPosted notifications —
     * exist exactly once for the journal write path.
     */
    public function announceRecap(Game $game, User $author): void
    {
        // Dashboard cache invalidation is handled by GameObserver::saved()
        // when it detects $game->wasChanged('recap').

        // Log activity
        $this->activityLogService->log(
            ActivityType::SessionRecapped,
            $author,
            $game,
            ['game_id' => $game->id, 'author_id' => $author->id],
        );

        // Notify approved participants (not the host)
        $participants = $game->participants()
            ->where('status', 'approved')
            ->where('user_id', '!=', $author->id)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($participants as $participant) {
            if (! ($participant instanceof User)) {
                continue;
            }
            try {
                $this->notificationService->send(
                    $participant,
                    new RecapPosted($game, $author),
                    NotificationCategory::SessionContent,
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send recap notification', [
                    'game_id' => $game->id,
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
