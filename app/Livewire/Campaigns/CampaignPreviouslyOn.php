<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\PreviouslyOnDraft;
use App\Services\GmMembershipService;
use App\Services\PreviouslyOnService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The GM's Previously On panel (M071/S04-T03), embedded at the top of
 * CampaignJournal's member-gated Journal tab.
 *
 * Draft-first per D221: the GM reviews the auto-composed plain-text
 * digest and publishes with one click — nothing is posted sight-unseen.
 * Delivery is entirely GameBulletinObserver's single fan-out; publish()
 * only stamps the draft and creates exactly one bulletin via
 * PreviouslyOnService (which the observer picks up untouched).
 *
 * D230 projection model: the panel targets the campaign's NEXT
 * SCHEDULED session's draft — never "newest by created_at" — so an
 * older session's unpublished draft can never masquerade as current
 * (upsertUnpublished supersedes those away anyway; the observer hook
 * recomposes on session edits). With no upcoming session the panel
 * falls back to the latest PUBLISHED draft purely to keep the
 * confirmation state honest; unpublished unpublishable drafts render
 * nothing.
 *
 * Separate nested component per the S02 composition pattern
 * (CampaignJournal is already ~440 lines); renders its own flash banner
 * because a nested action only re-renders the child (MEM1379).
 *
 * @property-read string $panelState
 * @property-read PreviouslyOnDraft|null $latestDraft
 */
class CampaignPreviouslyOn extends Component
{
    #[Locked]
    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    // ── Computed panel state ──────────────────────────────

    /**
     * Fail-closed visibility: renders nothing unless the viewer is the
     * campaign owner. The panel lives inside the viewJournal-gated tab,
     * but the owner check is defense in depth — players never see draft
     * or published-audit content from this component, and never even
     * trigger the draft query.
     */
    private function viewerIsOwner(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $this->campaign->owner_id === $user->id;
    }

    /**
     * The panel's state machine, newest draft only:
     *
     *  - 'none'      not the owner, or a member owner with no draft —
     *                renders nothing at all;
     *  - 'gate'      the owner fails the D185 member gate via the D223
     *                seam (GmMembershipService — the same check
     *                CampaignPolicy::managePreviouslyOn uses). Rendered
     *                regardless of draft existence, but never shows
     *                draft content;
     *  - 'draft'     an unpublished draft exists — plain-text preview,
     *                target session line, and the Publish button;
     *  - 'published' the latest draft is published — confirmation line
     *                with the timestamp and a link to the session's
     *                bulletin board.
     */
    #[Computed]
    public function panelState(): string
    {
        $user = Auth::user();

        if ($user === null || $this->campaign->owner_id !== $user->id) {
            return 'none';
        }

        if (! GmMembershipService::canUseMemberFeatures($user)) {
            return 'gate';
        }

        $draft = $this->latestDraft();

        if ($draft === null) {
            return 'none';
        }

        return $draft->published_at !== null ? 'published' : 'draft';
    }

    /**
     * The panel's draft (the publish target): the draft of the
     * campaign's next SCHEDULED session. Fallback with no upcoming
     * session: the latest published draft (confirmation state only) —
     * an unpublished draft for a non-scheduled session is unpublishable
     * and renders nothing. Only ever queried for the owner — see
     * {@see viewerIsOwner()}.
     */
    #[Computed]
    public function latestDraft(): ?PreviouslyOnDraft
    {
        if (! $this->viewerIsOwner()) {
            return null;
        }

        // The service's ONE definition of "next" (D230) — never a
        // second resolution that could disagree with the composer.
        $nextSession = app(PreviouslyOnService::class)->nextSession($this->campaign);

        if ($nextSession !== null) {
            /** @var PreviouslyOnDraft|null $draft */
            $draft = $this->campaign->previouslyOnDrafts()
                ->with('game')
                ->whereBelongsTo($nextSession)
                ->first();

            return $draft;
        }

        /** @var PreviouslyOnDraft|null $latest */
        $latest = $this->campaign->previouslyOnDrafts()
            ->with('game')
            ->latest('created_at')
            ->first();

        return $latest !== null && $latest->published_at !== null ? $latest : null;
    }

    // ── Actions ───────────────────────────────────────────

    /**
     * One-click publish of the reviewed draft. Pre-guarded by the
     * managePreviouslyOn policy (owner + D223 member seam — this also
     * covers a membership lapse between compose and publish); the
     * service re-validates the draft/session state and throws
     * translated LogicExceptions on exactly-once violations.
     *
     * No redirect: the panel re-renders on the same Journal tab and
     * flips to the published confirmation.
     */
    public function publish(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('managePreviouslyOn', $this->campaign)) {
            session()->flash('error', __('journal.error_previously_on_not_available'));

            return;
        }

        $draft = $this->latestDraft();

        if ($draft === null) {
            session()->flash('error', __('journal.error_previously_on_not_available'));

            return;
        }

        try {
            app(PreviouslyOnService::class)->publish($draft, $user);
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            // Same rationale as refresh(): publish also takes the
            // campaign-row lock and the draft row under lockForUpdate —
            // a lock timeout or serialization failure under load degrades
            // to the unavailable flash, never an uncaught error modal on
            // a one-click GM action. report() keeps the distinction
            // between infra degradation and a real defect (TypeError,
            // NRE) observable — the flash alone would swallow both.
            report($e);
            logger()->error('previously_on.publish_failed', [
                'campaign_id' => $this->campaign->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', __('journal.error_previously_on_not_available'));

            return;
        }

        session()->flash('success', __('journal.flash_previously_on_published'));
        unset($this->panelState, $this->latestDraft);
    }

    /**
     * Recompose the unpublished draft now (D230): the two automatic
     * triggers are session creation and the 24h reminder, so journal
     * writes made after the last compose (a late recap, moved goals,
     * fresh state changes) surface here on explicit GM action. Safe by
     * construction — drafts are machine-composed, so recomposing never
     * clobbers human edits.
     */
    public function refresh(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('managePreviouslyOn', $this->campaign)) {
            session()->flash('error', __('journal.error_previously_on_not_available'));

            return;
        }

        $draft = $this->latestDraft();

        // Nothing refreshable: no draft, already published, or its
        // session is gone (deleted sessions cascade the draft away).
        if ($draft === null || $draft->published_at !== null || $draft->game === null) {
            return;
        }

        // The service's own refusals are returns, but the campaign-row
        // lock can still surface as a serialization failure or statement
        // timeout under load — infra exceptions degrade to the same
        // unavailable flash the other panel actions use (the identical
        // call in GameObserver::composeQuietly catches \Throwable), never
        // an uncaught error modal on a panel refresh.
        try {
            app(PreviouslyOnService::class)->upsertUnpublished($this->campaign, $draft->game);
        } catch (\Throwable $e) {
            // report() keeps infra degradation distinguishable from a
            // real defect in the compose path — the flash alone would
            // swallow both (same discipline as publish()).
            report($e);
            logger()->error('previously_on.refresh_failed', [
                'campaign_id' => $this->campaign->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', __('journal.error_previously_on_not_available'));

            return;
        }

        session()->flash('success', __('journal.flash_previously_on_refreshed'));
        unset($this->panelState, $this->latestDraft);
    }

    public function render(): View
    {
        return view('livewire.campaigns.campaign-previously-on', [
            'panelState' => $this->panelState(),
            'latestDraft' => $this->latestDraft(),
        ]);
    }
}
