<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Game;
use App\Models\User;
use App\Notifications\RecapPosted;
use Illuminate\Support\Facades\Log;

class RecapService
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Write a recap for a completed game.
     *
     * Validates the game is completed, the user is the host (owner),
     * and content length is within limits. Sets the recap column,
     * logs activity, and notifies participants.
     */
    public function writeRecap(Game $game, User $author, string $content): void
    {
        if ($game->status !== 'completed') {
            throw new \LogicException(__('games.error_recap_game_not_completed'));
        }

        if (! empty($game->recap)) {
            throw new \LogicException(__('games.error_recap_already_written'));
        }

        if ($game->owner_id !== $author->id) {
            throw new \LogicException(__('games.error_recap_not_host'));
        }

        if (mb_strlen($content) > 2000) {
            throw new \LogicException(__('games.error_recap_too_long'));
        }

        if (empty(trim($content))) {
            throw new \LogicException(__('games.error_recap_empty'));
        }

        $game->update(['recap' => $content]);

        Log::info('Recap written for game', [
            'game_id' => $game->id,
            'author_id' => $author->id,
            'content_length' => mb_strlen($content),
        ]);

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
            try {
                $this->notificationService->send(
                    $participant,
                    new RecapPosted($game, $author),
                    \App\Enums\NotificationCategory::GameUpdated,
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
