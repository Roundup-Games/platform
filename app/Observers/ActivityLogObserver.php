<?php

namespace App\Observers;

use App\Enums\ActivityType;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\UserRelationship;
use App\Services\ActivityLogService;

/**
 * Observes Eloquent events on multiple models and logs activity entries.
 *
 * Registered on Game, Campaign, GameParticipant, Review, and UserRelationship.
 * Dispatches to model-specific handlers via match on the concrete type.
 * All logging is resilient — failures never block primary actions.
 */
class ActivityLogObserver
{
    public function __construct(
        private ActivityLogService $service,
    ) {}

    // ── Eloquent event entry points ────────────────────

    public function created($model): void
    {
        match (true) {
            $model instanceof Game => $this->handleGameCreated($model),
            $model instanceof Campaign => $this->handleCampaignCreated($model),
            $model instanceof Review => $this->handleReviewCreated($model),
            $model instanceof UserRelationship => $this->handleRelationshipCreated($model),
            $model instanceof GameParticipant => $this->handleParticipantCreated($model),
            default => null,
        };
    }

    public function updated($model): void
    {
        match (true) {
            $model instanceof Game && $model->isDirty('status') => $this->handleGameStatusChanged($model),
            $model instanceof GameParticipant && $model->isDirty('status') => $this->handleParticipantStatusChanged($model),
            default => null,
        };
    }

    // ── Game handlers ──────────────────────────────────

    private function handleGameCreated(Game $game): void
    {
        $owner = $game->owner;
        if ($owner) {
            $this->service->log(ActivityType::GameCreated, $owner, $game);
        }
    }

    private function handleGameStatusChanged(Game $game): void
    {
        $status = $game->status;

        if ($status === GameStatus::Completed) {
            $this->service->log(ActivityType::GameCompleted, $game->owner, $game);
            $this->service->logForParticipants(ActivityType::GameCompleted, $game);
        } elseif ($status === GameStatus::Canceled) {
            $this->service->log(ActivityType::GameCanceled, $game->owner, $game);
            $this->service->logForParticipants(ActivityType::GameCanceled, $game);
        }
    }

    // ── Campaign handler ───────────────────────────────

    private function handleCampaignCreated(Campaign $campaign): void
    {
        $owner = $campaign->owner;
        if ($owner) {
            $this->service->log(ActivityType::CampaignCreated, $owner, $campaign);
        }
    }

    // ── Participant handler ────────────────────────────

    private function handleParticipantCreated(GameParticipant $participant): void
    {
        if ($participant->status === ParticipantStatus::Approved) {
            $this->logPlayerJoined($participant);
        }
    }

    private function handleParticipantStatusChanged(GameParticipant $participant): void
    {
        if ($participant->status === ParticipantStatus::Approved) {
            $this->logPlayerJoined($participant);
        }
    }

    private function logPlayerJoined(GameParticipant $participant): void
    {
        $game = $participant->game;
        if ($game && $game->owner) {
            $this->service->log(
                ActivityType::PlayerJoined,
                $game->owner,
                $game,
                ['participant_user_id' => $participant->user_id],
            );
        }
    }

    // ── Review handler ─────────────────────────────────

    private function handleReviewCreated(Review $review): void
    {
        $gmProfile = $review->gmProfile;
        if ($gmProfile && $gmProfile->user) {
            $this->service->log(
                ActivityType::ReviewReceived,
                $gmProfile->user,
                $review,
            );
        }
    }

    // ── Follow handler ─────────────────────────────────

    private function handleRelationshipCreated(UserRelationship $relationship): void
    {
        if ($relationship->type === RelationshipType::Follow) {
            $followed = $relationship->related;
            if ($followed) {
                $this->service->log(
                    ActivityType::FollowReceived,
                    $followed,
                    $relationship,
                );
            }
        }
    }
}
