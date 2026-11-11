<?php

namespace App\Traits;

use App\Enums\CharacterStateChangeType;
use App\Enums\GameStatus;
use App\Enums\GoalStatus;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
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
     * Campaign-journal-only since M071/S03 (D219, cull completed):
     * one-shot games get the Epilogue bulletin instead of a recap form,
     * so this action always delegates to CampaignJournalService::
     * writeRecap — the kind=recap journal entry is the one and only
     * recap record, and the ceiling is 10000 characters. The recap
     * display block reads the game's recapEntry relation.
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

    // ── Session-end quick journal widgets (D235, spec §5) ──

    /**
     * Change a campaign goal's status from the session-end panel (D235,
     * spec §5's quick "add goal status change" widget).
     *
     * Write gate mirrors the Journal tab's changeGoalStatus exactly:
     * campaign GM only (isCampaignOwner, the tab's isGm semantics — not
     * viewJournal, which also admits seated players). The service
     * re-validates ownership on every path (defense in depth).
     *
     * The stamp is THE SESSION being ended (explicit $atGame), so the S04
     * "goals moved" diff and the Previously On digest attribute the move
     * to this session even when a later completed session already exists
     * — the tab's fallback (latest completed session) is never consulted.
     */
    public function sessionEndChangeGoalStatus(): void
    {
        $game = $this->getEntity();
        $campaign = $game->campaign;
        $viewer = authenticatedUser();

        if ($campaign === null || ! $this->isCampaignOwner()) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // The widget only renders on completed campaign sessions; the
        // action mirrors that boundary instead of trusting the render.
        if ($game->status !== GameStatus::Completed) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $status = GoalStatus::tryFrom($this->sessionGoalStatus);

        // Scoped to the campaign: a goal id from another campaign (or
        // invented) never resolves — the write path is not reached.
        $goal = $campaign->goals()->whereKey($this->sessionGoalId)->first();

        if ($goal === null || $status === null) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(CampaignJournalService::class)->changeGoalStatus($goal, $viewer, $status, $game);
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_goal_status_changed'));
        $this->reset('sessionGoalId', 'sessionGoalStatus');
    }

    /**
     * Log a character state change from the session-end panel (D235,
     * spec §5's quick "log character state" widget).
     *
     * Same GM gate as the goal widget, but the target character is the
     * ACTING user's own seated participant row ONLY — the quick widget
     * never writes another member's history (per-character logging
     * stays on the Journal tab's GM form). The stamp is the session
     * being ended ($game), keeping the state timeline session-bound.
     */
    public function sessionEndLogStateChange(): void
    {
        $game = $this->getEntity();
        $campaign = $game->campaign;
        $viewer = authenticatedUser();

        if ($campaign === null || ! $this->isCampaignOwner()) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($game->status !== GameStatus::Completed) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $type = CharacterStateChangeType::tryFrom($this->sessionStateType);

        if ($type === null) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // The acting user's own seated row (Approved or Benched, the
        // writeInCharacter member set) — the same scoping the Journal
        // tab's eligibleParticipant applies, so a GM without their own
        // character row never writes through this widget.
        $ownParticipant = $campaign->participants()
            ->whereBelongsTo($viewer)
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->first();

        if (! $ownParticipant instanceof CampaignParticipant) {
            session()->flash('error', __('journal.error_participant_not_seated'));

            return;
        }

        try {
            app(CampaignJournalService::class)->logStateChange(
                $campaign,
                $viewer,
                $ownParticipant,
                $type,
                $this->sessionStateDelta,
                $game,
            );
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_state_change_logged'));
        $this->reset('sessionStateType', 'sessionStateDelta');
    }
}
