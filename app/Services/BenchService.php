<?php

namespace App\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        if ((string) $entity->owner_id === (string) $user->id) {
            throw new \LogicException('Cannot add to bench: you are the host.');
        }

        // Wrap in transaction with lock for concurrency safety
        return DB::transaction(function () use ($entity, $user, $entityType, $isCampaign) {
            // Lock entity row to serialize concurrent bench adds
            $entityClass = $isCampaign ? Campaign::class : Game::class;
            $lockedEntity = $entityClass::lockForUpdate()->findOrFail($entity->id);

            if (! $lockedEntity->isAtCapacity()) {
                throw new \LogicException('Cannot add to bench: entity is not full.');
            }

            $existing = $lockedEntity->participants()->whereBelongsTo($user)->first();
            if ($existing !== null) {
                throw new \LogicException('User is already a participant.');
            }

            $foreignKey = $isCampaign ? 'campaign_id' : 'game_id';

            try {
                $participant = $lockedEntity->participants()->create([
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
     * Get all benched participants for an entity.
     *
     * @return EloquentCollection<int, CampaignParticipant>|EloquentCollection<int, GameParticipant>
     */
    public function getBenchList(Campaign|Game $entity): EloquentCollection
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
        /** @var Collection<int, GameParticipant|CampaignParticipant> $benched */
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
            // Stamp the audit fields for uniformity with ParticipantLifecycle::depart().
            // removed_by is null — entity cancellation is system-initiated; these benched
            // participants were never Approved so reliability scoring is correctly N/A.
            $participant->update([
                'status' => ParticipantStatus::Rejected->value,
                'removed_at' => now(),
                'removed_by' => null,
            ]);
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
