<?php

namespace App\Traits;

use App\Services\ParticipantLifecycle;

trait HandlesBench
{
    public function promoteFromBench(string $participantId): void
    {
        $this->authorize('update', $this->getEntity());

        $participant = $this->findParticipantOrFail($participantId);
        $viewer = authenticatedUser();

        try {
            app(ParticipantLifecycle::class)->promoteFromBench($participant, $viewer);
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
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(ParticipantLifecycle::class)->removeFromBench($participant, $viewer);
            session()->flash('success', __('games.flash_left_bench'));
        } catch (\LogicException $e) {
            session()->flash('error', __('common.error_participant_not_benched'));
        }
    }
}
