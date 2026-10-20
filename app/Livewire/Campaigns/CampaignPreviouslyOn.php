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
            && (string) $this->campaign->owner_id === (string) $user->id;
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

        if ($user === null || (string) $this->campaign->owner_id !== (string) $user->id) {
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
     * The campaign's latest draft by created_at (the publish target), or
     * null when none exists. Only ever queried for the owner — see
     * {@see viewerIsOwner()}.
     */
    #[Computed]
    public function latestDraft(): ?PreviouslyOnDraft
    {
        if (! $this->viewerIsOwner()) {
            return null;
        }

        /** @var PreviouslyOnDraft|null $draft */
        $draft = $this->campaign->previouslyOnDrafts()
            ->with('game')
            ->latest('created_at')
            ->first();

        return $draft;
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
        }

        session()->flash('success', __('journal.flash_previously_on_published'));
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
