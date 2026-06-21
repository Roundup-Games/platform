<?php

namespace App\Traits;

use App\Dto\EntityMeta;
use App\Enums\ParticipantStatus;
use App\Services\BenchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesBench
{
    public function promoteFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        try {
            app(BenchService::class)->promoteFromBench($participant, $viewer);
            session()->flash('success', __('games.flash_promote_from_bench_success'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_benched'));
        }
    }

    /**
     * Leave the bench as a benched participant.
     *
     * Bench promotion is host-controlled — leaving only removes the player.
     * Unlike waitlist leave, this intentionally does NOT trigger automatic
     * promotion. Bench slots are not a FIFO queue; the host decides whom
     * to promote and when.
     */
    public function leaveBench(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $entity = $this->getEntity();
        $meta = EntityMeta::fromEntity($entity);

        $didLeave = false;
        DB::transaction(function () use ($participant, &$didLeave) {
            // Lock the participant row to prevent concurrent status changes
            // (e.g., host promoting while this leave action runs)
            $locked = $participant->newQuery()
                ->where('id', $participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ParticipantStatus::Benched) {
                return;
            }

            $locked->update(['status' => ParticipantStatus::Rejected]);
            $didLeave = true;
        });

        if ($didLeave) {
            Log::info('bench.participant_left', [
                'entity_type' => $meta->type,
                'entity_id' => $entity->id,
                'user_id' => $viewer->id,
                'participant_id' => $participant->id,
            ]);

            session()->flash('success', __('games.flash_left_bench'));
        }
    }
}
