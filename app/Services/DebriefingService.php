<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Notifications\DebriefingAvailable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class DebriefingService
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Submit a debriefing response for a game session.
     *
     * Validates: game is completed, user is an approved participant,
     * game has debriefing tools, and user hasn't already submitted.
     *
     * @param  array<string, mixed>  $responses
     */
    public function submitDebriefing(Game $game, User $user, array $responses): SessionDebriefing
    {
        if ($game->status !== GameStatus::Completed) {
            throw new \LogicException(__('games.error_debriefing_game_not_completed'));
        }

        if (! $game->hasDebriefingTools()) {
            throw new \LogicException(__('games.error_debriefing_no_debriefing_tools'));
        }

        $isParticipant = $game->participants()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();

        if (! $isParticipant) {
            throw new \LogicException(__('games.error_debriefing_not_participant'));
        }

        if ((string) $game->owner_id === (string) $user->id) {
            throw new \LogicException(__('games.error_debriefing_host_cannot_submit'));
        }

        $existing = SessionDebriefing::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            throw new \LogicException(__('games.error_debriefing_already_submitted'));
        }

        $prompts = $game->getDebriefingPrompts();
        $filteredResponses = [];
        foreach ($prompts as $key => $promptData) {
            $response = $responses[$key] ?? null;
            if (isset($response) && is_string($response) && trim($response) !== '') {
                $filteredResponses[$key] = trim($response);
            }
        }

        if (empty($filteredResponses)) {
            throw new \LogicException(__('games.error_debriefing_empty_responses'));
        }

        $debriefing = SessionDebriefing::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'tool_type' => $game->getDebriefingToolType(),
            'responses' => $filteredResponses,
            'submitted_at' => now(),
        ]);

        Log::info('Debriefing submitted', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'tool_type' => $debriefing->tool_type,
            'response_count' => count($filteredResponses),
        ]);

        $this->activityLogService->log(
            ActivityType::DebriefingSubmitted,
            $user,
            $game,
            [
                'game_id' => $game->id,
                'user_id' => $user->id,
                'tool_type' => $debriefing->tool_type,
                'participant_count' => $game->participants()
                    ->where('status', ParticipantStatus::Approved->value)
                    ->count(),
            ],
        );

        return $debriefing;
    }

    /**
     * Send DebriefingAvailable notifications to all approved participants.
     * Called when a game completes and has debriefing tools enabled.
     */
    public function notifyParticipants(Game $game): void
    {
        $participants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
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
                    new DebriefingAvailable($game),
                    NotificationCategory::GameUpdated,
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send debriefing notification', [
                    'game_id' => $game->id,
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Debriefing available notifications sent', [
            'game_id' => $game->id,
            'participant_count' => $participants->count(),
        ]);
    }

    /**
     * Get aggregated anonymized debriefing summary for participants.
     *
     * Returns counts and themes without attribution.
     *
     * @return array<string, mixed>
     */
    public function getAnonymizedSummary(Game $game): array
    {
        $debriefings = SessionDebriefing::where('game_id', $game->id)
            ->submitted()
            ->get();

        if ($debriefings->isEmpty()) {
            return ['total_submissions' => 0, 'prompts' => []];
        }

        $promptSummary = [];
        foreach ($debriefings as $debriefing) {
            foreach ($debriefing->responses as $key => $response) {
                if (! isset($promptSummary[$key])) {
                    $promptSummary[$key] = [];
                }
                $promptSummary[$key][] = $response;
            }
        }

        return [
            'total_submissions' => $debriefings->count(),
            'tool_type' => $debriefings->first()->tool_type,
            'prompts' => $promptSummary,
        ];
    }

    /**
     * Get debriefing submissions with responses for the host view.
     *
     * @return Collection<int, SessionDebriefing>
     */
    public function getHostDebriefings(Game $game): Collection
    {
        return SessionDebriefing::where('game_id', $game->id)
            ->submitted()
            ->with('user')
            ->orderBy('submitted_at', 'desc')
            ->get();
    }
}
