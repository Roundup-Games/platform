<?php

namespace App\Services;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class BenchService
{
    /**
     * Add a user to the bench for a campaign or campaign session.
     *
     * Creates a participant record with status=benched and benched_at=now().
     *
     * @param  Campaign|Game  $entity  Campaign or campaign-session Game
     * @return CampaignParticipant|GameParticipant
     *
     * @throws \LogicException if entity is not a campaign/session, not full, or user is already a participant
     */
    public function addToBench(Campaign|Game $entity, User $user): CampaignParticipant|GameParticipant
    {
        $entityType = $entity instanceof Campaign ? 'campaign' : 'game';
        $isCampaign = $entity instanceof Campaign;

        // Verify entity supports benching
        if (! $isCampaign && $entity->campaign_id === null) {
            throw new \LogicException('Bench is only available for campaigns and campaign sessions.');
        }

        // Check entity is full
        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < $entity->max_players) {
            throw new \LogicException('Cannot add to bench: entity is not full.');
        }

        // Check user is not already a participant
        $existing = $entity->participants()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            throw new \LogicException('User is already a participant.');
        }

        $participantClass = $isCampaign ? CampaignParticipant::class : GameParticipant::class;
        $foreignKey = $isCampaign ? 'campaign_id' : 'game_id';

        $participant = $participantClass::create([
            $foreignKey => $entity->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched->value,
            'benched_at' => now(),
        ]);

        Log::info('bench.placed', [
            'entity_type' => $entityType,
            $foreignKey => $entity->id,
            'user_id' => $user->id,
            'participant_id' => $participant->id,
        ]);

        return $participant;
    }

    /**
     * Promote a benched participant to approved status.
     *
     * @throws \LogicException if participant is not benched or entity has no capacity
     */
    public function promoteFromBench(string $participantId, string $entityType): void
    {
        $isCampaign = $entityType === 'campaign';

        $participantClass = $isCampaign ? CampaignParticipant::class : GameParticipant::class;
        $foreignKey = $isCampaign ? 'campaign_id' : 'game_id';

        $participant = $participantClass::where('id', $participantId)->firstOrFail();

        if ($participant->status !== ParticipantStatus::Benched) {
            throw new \LogicException('Participant is not on the bench.');
        }

        // Load entity and check capacity
        $entityClass = $isCampaign ? Campaign::class : Game::class;
        $entity = $entityClass::findOrFail($participant->$foreignKey);

        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount >= $entity->max_players) {
            throw new \LogicException('Cannot promote: entity is full.');
        }

        $participant->update([
            'status' => ParticipantStatus::Approved->value,
            'benched_at' => null,
        ]);

        Log::info('bench.promoted', [
            'entity_type' => $entityType,
            $foreignKey => $entity->id,
            'user_id' => $participant->user_id,
            'promoted_by' => auth()->id(),
        ]);
    }

    /**
     * Get all benched participants for an entity.
     *
     * @param  Campaign|Game  $entity
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBenchList(Campaign|Game $entity): \Illuminate\Database\Eloquent\Collection
    {
        return $entity->participants()
            ->where('status', ParticipantStatus::Benched->value)
            ->get();
    }
}
