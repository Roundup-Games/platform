<?php

namespace App\Livewire\GM\Charter;

use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Services\Charter\CharterMemberService;
use App\Services\Charter\CharterService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Charter audit export view (M070/S04-T04, R085) — the third member
 * convenience: the immutable version + confirmation history IS the audit
 * trail (docs/spec/campaign-charter-concept.md §3), it just needs an
 * exportable, print-ready rendering. Read-only — no action mutates
 * anything here, the only interaction is window.print() in the browser.
 *
 * Owner-only behind the same gate idiom as CharterComposer (R084:
 * ownership is the campaign owner_id, never a membership check on the
 * gate itself). A campaign without a charter 404s like the composer. The
 * member seam (CharterMemberService::canUseConveniences, the single
 * seam the pricing milestone can tighten) decides what renders:
 *  - free owner  → polite member-feature panel, no history rendered
 *  - member      → the audit: header (campaign, charter title, status
 *                  chip, current version), version history table, and
 *                  signature history grouped by version
 *
 * Privacy contract (concept §2): §2 private boundary answers live in
 * session_zero_confirmations.private_answers and are NEVER selected for
 * display here — only display name/email, role, confirmed_at, and the
 * status discriminator render. The view says so explicitly.
 *
 * Lifecycle states: drafts (no versions yet) render a not-published note
 * instead of empty tables; archived charters remain fully exportable —
 * frozen history is the point of the export.
 */
#[Layout('layouts.app')]
class CharterAudit extends Component
{
    #[Locked]
    public string $campaignId = '';

    public Campaign $campaign;

    public ?SessionZeroSurvey $charter = null;

    public function mount(string $id): void
    {
        $user = authenticatedUser();

        $this->campaign = Campaign::findOrFail($id);

        // Same gate as the composer: CampaignPolicy::manageCharter
        // (owner-only, R084; global admins pass via before()).
        abort_unless($user->can('manageCharter', $this->campaign), 403);

        $this->campaignId = (string) $this->campaign->id;

        $this->charter = CharterService::charterFor($this->campaign);

        if ($this->charter === null) {
            abort(404);
        }
    }

    public function render(): View
    {
        $member = CharterMemberService::canUseConveniences(authenticatedUser());

        return view('livewire.gm.charter.charter-audit', [
            'campaign' => $this->campaign,
            'charter' => $this->charter,
            'memberConveniences' => $member,
            // Free owners render only the member-feature panel — never
            // query the audit trail (confirmations carry the sensitive
            // §2 private answers) for a surface that cannot display it.
            'versions' => $member ? $this->auditVersions() : collect(),
        ]);
    }

    /**
     * The audit rows: every published version in chronological order
     * (v1 first — an audit trail reads forward), each with its pinned
     * confirmations eager-loaded (participant name resolution through
     * the User relation; deterministic confirmed_at ordering inside a
     * version). private_answers columns are simply never selected for
     * display anywhere in this pipeline.
     *
     * @return Collection<int, SessionZeroSurveyVersion>
     */
    private function auditVersions(): Collection
    {
        $charter = $this->charter;

        if ($charter === null) {
            return collect();
        }

        return $charter->versions()
            ->reorder('version_number', 'asc')
            // Version rows carry the full charter content snapshot
            // (jsonb, tens of KB each) and the audit view renders only
            // number/date/material/changelog — select those.
            ->select(['id', 'session_zero_survey_id', 'version_number', 'changelog', 'is_material', 'published_at'])
            // Projection is the privacy contract made structural: only
            // the columns the view renders are ever fetched —
            // private_answers/attestations stay in their rows.
            ->with(['confirmations' => fn ($query) => $query
                ->select(['id', 'session_zero_survey_version_id', 'user_id', 'role', 'status', 'confirmed_at'])
                ->with('user')
                ->orderBy('confirmed_at')])
            ->get();
    }
}
