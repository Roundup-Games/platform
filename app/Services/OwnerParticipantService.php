<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
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
        try {
            $participant = GameParticipant::updateOrCreate(
                ['game_id' => $game->id, 'user_id' => $game->owner_id],
                ['role' => ParticipantRole::Owner, 'status' => ParticipantStatus::Approved],
            );
        } catch (QueryException $e) {
            // Concurrent insert hit unique constraint — re-query the winner
            $participant = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $game->owner_id)
                ->firstOrFail();

            // Upgrade role if the winner had a non-owner role
            if ($participant->role !== ParticipantRole::Owner) {
                $participant->update(['role' => ParticipantRole::Owner]);
            }
        }

        if ($participant->wasRecentlyCreated) {
            Log::info('owner_participant.created', [
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'participant_id' => $participant->id,
            ]);
        }

        return $participant;
    }

    /**
     * Ensure an owner participant exists for the given campaign.
     *
     * Same atomicity guarantees as ensureOwnerParticipant.
     */
    public function ensureCampaignOwnerParticipant(Campaign $campaign): CampaignParticipant
    {
        try {
            $participant = CampaignParticipant::updateOrCreate(
                ['campaign_id' => $campaign->id, 'user_id' => $campaign->owner_id],
                ['role' => ParticipantRole::Owner, 'status' => ParticipantStatus::Approved],
            );
        } catch (QueryException $e) {
            // Concurrent insert hit unique constraint — re-query the winner
            $participant = CampaignParticipant::where('campaign_id', $campaign->id)
                ->where('user_id', $campaign->owner_id)
                ->firstOrFail();

            if ($participant->role !== ParticipantRole::Owner) {
                $participant->update(['role' => ParticipantRole::Owner]);
            }
        }

        if ($participant->wasRecentlyCreated) {
            Log::info('campaign_owner_participant.created', [
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->owner_id,
                'participant_id' => $participant->id,
            ]);
        }

        return $participant;
    }
}
