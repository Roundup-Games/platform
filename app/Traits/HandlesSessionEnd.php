<?php

namespace App\Traits;

use App\Services\CampaignJournalService;
use App\Services\DebriefingService;

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
     * Write a recap for the completed game's campaign journal (host only).
     *
     * Campaign-journal-only since M071/S03 (D219): the S01-era branch on
     * the legacy RecapService column write is retired — one-shot games
     * get the Epilogue bulletin instead of a recap form — so this action
     * always delegates to CampaignJournalService::writeRecap: the
     * kind=recap journal entry is the source of truth, games.recap is
     * synced in the same transaction, and the ceiling is 10000
     * characters. The recap DISPLAY block in the session-end partial
     * stays untouched: it reads the games.recap projection (D220).
     */
    public function writeRecap(): void
    {
        $viewer = authenticatedUser();

        $this->validate([
            'recapContent' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        try {
            app(CampaignJournalService::class)->writeRecap(
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
