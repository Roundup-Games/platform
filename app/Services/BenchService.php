<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        if (! $isCampaign && ! $entity->isBenchMode()) {
            throw new \LogicException('Bench is only available for campaigns, campaign sessions, and games with bench_mode enabled.');
        }

        // Owner cannot be benched on their own entity
        if ($entity->owner_id === $user->id) {
            throw new \LogicException('Cannot add to bench: you are the host.');
        }

        // Wrap in transaction with lock for concurrency safety
        return DB::transaction(function () use ($entity, $user, $entityType, $isCampaign) {
            // Lock entity row to serialize concurrent bench adds
            $entityClass = $isCampaign ? Campaign::class : Game::class;
            $lockedEntity = $entityClass::lockForUpdate()->findOrFail($entity->id);

            $approvedCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->count();

            if ($approvedCount < $lockedEntity->max_players) {
                throw new \LogicException('Cannot add to bench: entity is not full.');
            }

            $existing = $lockedEntity->participants()->where('user_id', $user->id)->first();
            if ($existing !== null) {
                throw new \LogicException('User is already a participant.');
            }

            $participantClass = $isCampaign ? CampaignParticipant::class : GameParticipant::class;
            $foreignKey = $isCampaign ? 'campaign_id' : 'game_id';

            try {
                $participant = $participantClass::create([
                    $foreignKey => $lockedEntity->id,
                    'user_id' => $user->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => ParticipantStatus::Benched->value,
                    'benched_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Handle concurrent insert race (unique constraint on entity_id + user_id).
                // Re-throw all other errors (deadlocks, connectivity, schema issues).
                if ($e->getCode() !== '23505') {
                    throw $e;
                }

                throw new \LogicException('User is already a participant.');
            }

            Log::info('bench.placed', [
                'entity_type' => $entityType,
                $foreignKey => $lockedEntity->id,
                'user_id' => $user->id,
                'participant_id' => $participant->id,
            ]);

            return $participant;
        });
    }

    /**
     * Promote a benched participant to approved status.
     *
     * @param  string  $participantId  UUID of the participant to promote
     * @param  string  $entityType  'campaign' or 'game'
     * @param  User|null  $promoter  User performing the promotion (null for system/queue)
     *
     * @throws \LogicException if participant is not benched or entity has no capacity
     */
    public function promoteFromBench(string $participantId, string $entityType, ?User $promoter = null): void
    {
        $promoterId = $promoter?->id ?? 'system';

        DB::transaction(function () use ($participantId, $entityType, $promoterId) {
            $isCampaign = $entityType === 'campaign';

            $participantClass = $isCampaign ? CampaignParticipant::class : GameParticipant::class;
            $foreignKey = $isCampaign ? 'campaign_id' : 'game_id';

            $participant = $participantClass::lockForUpdate()->where('id', $participantId)->firstOrFail();

            if ($participant->status !== ParticipantStatus::Benched) {
                throw new \LogicException('Participant is not on the bench.');
            }

            $entityClass = $isCampaign ? Campaign::class : Game::class;
            $lockedEntity = $entityClass::lockForUpdate()->findOrFail($participant->$foreignKey);

            $approvedCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->count();

            if ($approvedCount >= $lockedEntity->max_players) {
                throw new \LogicException('Cannot promote: entity is full.');
            }

            $participant->update([
                'status' => ParticipantStatus::Approved->value,
                'benched_at' => null,
            ]);

            Log::info('bench.promoted', [
                'entity_type' => $entityType,
                $foreignKey => $lockedEntity->id,
                'user_id' => $participant->user_id,
                'promoted_by' => $promoterId,
            ]);
        });
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
            ->with('user')
            ->get();
    }

    /**
     * Handle entity cancellation — reject all benched participants (excluding owner).
     */
    public function handleEntityCancellation(Campaign|Game $entity): void
    {
        $benched = $entity->participants()
            ->where('status', ParticipantStatus::Benched->value)
            ->get();

        // Owner should never be benched — if found, log a warning before excluding
        $ownerBenched = $benched->first(fn ($p) => $p->role === ParticipantRole::Owner);
        if ($ownerBenched) {
            Log::warning('bench.cancel_found_owner_benched: data integrity issue', [
                'entity_id' => $entity->id,
                'owner_participant_id' => $ownerBenched->id,
            ]);
            $benched = $benched->filter(fn ($p) => $p->role !== ParticipantRole::Owner);
        }

        foreach ($benched as $participant) {
            $participant->update(['status' => ParticipantStatus::Rejected->value]);
        }

        $entityType = $entity instanceof Campaign ? 'campaign' : 'game';
        $foreignKey = $entity instanceof Campaign ? 'campaign_id' : 'game_id';

        Log::info('bench.entity_cancelled', [
            'entity_type' => $entityType,
            $foreignKey => $entity->id,
            'affected_count' => $benched->count(),
        ]);
    }
}
