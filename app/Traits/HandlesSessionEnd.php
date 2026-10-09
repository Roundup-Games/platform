<?php

namespace App\Traits;

use App\Services\DebriefingService;
use App\Services\RecapService;

trait HandlesSessionEnd
{
    /**
     * Submit a debriefing response for the current game.
     */
    public function submitDebriefing(): void
    {
        $viewer = authenticatedUser();

        try {
            app(DebriefingService::class)->submitDebriefing(
                $this->getEntity(),
                $viewer,
                $this->debriefingResponses,
            );

            $this->debriefingResponses = [];
            session()->flash('success', __('games.flash_debriefing_submitted'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Recap Action ───────────────────────────────────

    /**
     * Write a recap for the completed game (host only).
     */
    public function writeRecap(): void
    {
        $viewer = authenticatedUser();

        $this->validate([
            'recapContent' => ['required', 'string', 'max:2000', 'min:1'],
        ]);

        try {
            app(RecapService::class)->writeRecap(
                $this->getEntity(),
                $viewer,
                $this->recapContent ?? '',
            );

            $this->recapContent = null;
            $this->getEntity()->refresh();
            session()->flash('success', __('games.flash_recap_written'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
}
