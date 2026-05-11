<?php

namespace App\Traits;

use App\Enums\ParticipantStatus;
use App\Services\BenchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesBench
{
    public function promoteFromBench(string $participantId): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->getEntity()->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if (! $this->getEntity()->isBenchMode()) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            $entityType = strtolower($this->getEntityName());
            app(BenchService::class)->promoteFromBench($participantId, $entityType);
            session()->flash('success', __('games.flash_promote_from_bench_success'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
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
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $entity = $this->getEntity();
        $entityType = strtolower($this->getEntityName());

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
                'entity_type' => $entityType,
                'entity_id' => $entity->id,
                'user_id' => $viewer->id,
                'participant_id' => $participant->id,
            ]);

            session()->flash('success', __('games.flash_left_bench'));
        }
    }
}
