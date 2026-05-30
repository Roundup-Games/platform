<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerParticipantService
{
    /**
     * Ensure an owner participant exists for the given game.
     *
     * Idempotent — if a GameParticipant record already exists with
     * role=Owner for this game's owner, it is returned as-is.
     */
    public function ensureOwnerParticipant(Game $game): GameParticipant
    {
        return DB::transaction(function () use ($game): GameParticipant {
            $existing = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $game->owner_id)
                ->first();

            if ($existing) {
                // Upgrade to owner role if needed
                if ($existing->role !== ParticipantRole::Owner) {
                    $existing->update(['role' => ParticipantRole::Owner]);
                }

                return $existing;
            }

            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'role' => ParticipantRole::Owner,
                'status' => ParticipantStatus::Approved,
            ]);

            Log::info('owner_participant.created', [
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'participant_id' => $participant->id,
            ]);

            return $participant;
        });
    }

    /**
     * Ensure an owner participant exists for the given campaign.
     *
     * Idempotent — if a CampaignParticipant record already exists with
     * role=Owner for this campaign's owner, it is returned as-is.
     */
    public function ensureCampaignOwnerParticipant(Campaign $campaign): CampaignParticipant
    {
        return DB::transaction(function () use ($campaign): CampaignParticipant {
            $existing = CampaignParticipant::where('campaign_id', $campaign->id)
                ->where('user_id', $campaign->owner_id)
                ->first();

            if ($existing) {
                // Upgrade to owner role if needed
                if ($existing->role !== ParticipantRole::Owner) {
                    $existing->update(['role' => ParticipantRole::Owner]);
                }

                return $existing;
            }

            $participant = CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->owner_id,
                'role' => ParticipantRole::Owner,
                'status' => ParticipantStatus::Approved,
            ]);

            Log::info('campaign_owner_participant.created', [
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->owner_id,
                'participant_id' => $participant->id,
            ]);

            return $participant;
        });
    }
}
