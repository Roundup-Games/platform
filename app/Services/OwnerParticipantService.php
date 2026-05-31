<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class OwnerParticipantService
{
    /**
     * Ensure an owner participant exists for the given game.
     *
     * Idempotent — uses updateOrCreate to atomically create or upgrade.
     * The unique constraint on (game_id, user_id) guarantees correctness
     * under concurrent calls: the second caller either finds the existing
     * row (via updateOrCreate's internal SELECT) or hits the constraint,
     * which we catch and resolve by re-querying.
     */
    public function ensureOwnerParticipant(Game $game): GameParticipant
    {
        /** @var GameParticipant $participant */
        $participant = $this->ensureForEntity(
            $game,
            'game_id',
            GameParticipant::class,
            'game',
        );

        return $participant;
    }

    /**
     * Ensure an owner participant exists for the given campaign.
     *
     * Same atomicity guarantees as ensureOwnerParticipant.
     */
    public function ensureCampaignOwnerParticipant(Campaign $campaign): CampaignParticipant
    {
        /** @var CampaignParticipant $participant */
        $participant = $this->ensureForEntity(
            $campaign,
            'campaign_id',
            CampaignParticipant::class,
            'campaign',
        );

        return $participant;
    }

    /**
     * Generic owner-participant ensure for any entity type.
     *
     * Handles the updateOrCreate + unique constraint race condition in one
     * place so game and campaign paths stay in lockstep.
     *
     * @param  class-string<GameParticipant|CampaignParticipant>  $participantClass
     * @return GameParticipant|CampaignParticipant
     */
    private function ensureForEntity(
        Game|Campaign $entity,
        string $foreignKey,
        string $participantClass,
        string $logLabel,
    ): Model {
        $ownerId = $entity->owner_id;

        try {
            $participant = $participantClass::updateOrCreate(
                [$foreignKey => $entity->id, 'user_id' => $ownerId],
                ['role' => ParticipantRole::Owner, 'status' => ParticipantStatus::Approved],
            );
        } catch (QueryException $e) {
            // Only handle unique constraint violations (concurrent insert race).
            // Re-throw all other errors (deadlocks, connectivity, schema issues).
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            // Concurrent insert hit unique constraint — re-query the winner
            $participant = $participantClass::where($foreignKey, $entity->id)
                ->where('user_id', $ownerId)
                ->firstOrFail();

            // Upgrade role if the winner had a non-owner role
            if ($participant->role !== ParticipantRole::Owner) {
                $participant->update(['role' => ParticipantRole::Owner]);
            }
        }

        if ($participant->wasRecentlyCreated) {
            Log::info("{$logLabel}_owner_participant.created", [
                "{$logLabel}_id" => $entity->id,
                'user_id' => $ownerId,
                'participant_id' => $participant->id,
            ]);
        }

        return $participant;
    }
}
